<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamEvent;
use App\Models\ExamPaper;
use App\Models\ExamRecord;
use App\Models\ExamRecordAnswer;
use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ExamController extends Controller
{
    // 断网后的宽限时间(秒)：超过截止时间这么久仍未交卷，转监考处理而非直接判作弊
    public const SUBMIT_GRACE_SECONDS = 120;
    // 两次心跳间隔超过该阈值(秒)，服务端可据此认定中间发生过断网
    public const HEARTBEAT_GAP_SECONDS = 45;

    public function index(Request $request)
    {
        $examPapers = ExamPaper::with('creator')
            ->where('status', 1)
            ->orderBy('id', 'desc')
            ->paginate($perPage = $request->input('per_page', 15));

        return response()->json([
            'exam_papers' => $examPapers,
        ]);
    }

    public function start(Request $request, ExamPaper $examPaper)
    {
        $existingRecord = ExamRecord::where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->whereIn('status', [ExamRecord::STATUS_IN_PROGRESS, ExamRecord::STATUS_AWAITING_REVIEW])
            ->first();

        if ($existingRecord) {
            // 已进入待监考处理：不允许直接继续，需等待老师延时/收卷
            if ($existingRecord->status === ExamRecord::STATUS_AWAITING_REVIEW
                || ($existingRecord->deadline_at && now()->greaterThan($existingRecord->deadline_at->copy()->addSeconds(self::SUBMIT_GRACE_SECONDS)))) {
                return response()->json([
                    'message' => '该场考试已超过允许时长，正在等待监考老师处理',
                    'record_status' => ExamRecord::STATUS_AWAITING_REVIEW,
                    'review_reason' => $existingRecord->review_reason,
                    'exam_record' => $existingRecord,
                ], 409);
            }
            // 进行中：不重复开考，返回续考所需的全部数据
            return $this->buildExamResponse($existingRecord, $examPaper, '您已有进行中的考试，已为您恢复', 200, $request);
        }

        $now = now();
        $deviceId = (string) $request->input('device_id', '');

        $record = ExamRecord::create([
            'user_id' => $request->user()->id,
            'exam_paper_id' => $examPaper->id,
            'start_time' => $now,
            'deadline_at' => $now->copy()->addMinutes($examPaper->total_time),
            'last_heartbeat_at' => $now,
            'device_id' => $deviceId ?: null,
            'status' => ExamRecord::STATUS_IN_PROGRESS,
        ]);

        $this->logEvent($record, ExamEvent::TYPE_HEARTBEAT, $request, [
            'phase' => 'exam_started',
        ]);

        return $this->buildExamResponse($record, $examPaper, '考试开始', 200, $request);
    }

    public function getQuestions(Request $request, ExamPaper $examPaper)
    {
        $record = ExamRecord::where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->whereIn('status', [ExamRecord::STATUS_IN_PROGRESS, ExamRecord::STATUS_AWAITING_REVIEW])
            ->firstOrFail();

        return $this->buildExamResponse($record, $examPaper, 'ok', 200, $request);
    }

    /**
     * 续考：刷新页面 / 断网恢复 / 换设备登录后拉取考试现场。
     * 与 getQuestions 的区别：会登记刷新、恢复、设备变更事件，供后台甄别。
     */
    public function resume(Request $request, ExamPaper $examPaper)
    {
        $record = ExamRecord::where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->firstOrFail();

        if (in_array($record->status, [ExamRecord::STATUS_GRADED, ExamRecord::STATUS_SUBMITTED, ExamRecord::STATUS_TERMINATED], true)) {
            return response()->json([
                'message' => '该场考试已结束',
                'record_status' => $record->status,
                'exam_record' => $record,
            ], 409);
        }

        $deviceId = (string) $request->input('device_id', '');
        $reason = (string) $request->input('reason', 'page_refresh'); // page_refresh | reconnect | device_switch
        $clientOfflineStart = $request->input('offline_since_ts'); // 考生端记录的断网起点(毫秒)

        $deviceSwitched = $deviceId !== '' && $record->device_id && $deviceId !== $record->device_id;

        // 结算最近一次断网时长：优先用考生端 clientTs，异常时回退为心跳缺口
        $offlineDuration = 0;
        if ($reason === 'reconnect' || $reason === 'device_switch') {
            if ($clientOfflineStart && is_numeric($clientOfflineStart)) {
                $offlineDuration = max(0, (int) round((now()->getTimestampMs() - (int) $clientOfflineStart) / 1000));
            } elseif ($record->last_heartbeat_at) {
                $offlineDuration = max(0, (int) $record->last_heartbeat_at->diffInSeconds(now()));
            }
            // 不信任客户端把断网报得过长：超过心跳缺口+宽限则以心跳缺口为准
            $heartbeatGap = $record->last_heartbeat_at
                ? max(0, (int) $record->last_heartbeat_at->diffInSeconds(now()))
                : 0;
            if ($offlineDuration > $heartbeatGap + self::SUBMIT_GRACE_SECONDS) {
                $offlineDuration = $heartbeatGap;
            }

            if ($offlineDuration > 0) {
                $record->increment('offline_total', $offlineDuration);
                $record->refresh();
            }

            $this->logEvent($record, ExamEvent::TYPE_RECONNECT, $request, [
                'offline_duration' => $offlineDuration,
                'resume_reason' => $reason,
            ], $offlineDuration);
        } else {
            $this->logEvent($record, ExamEvent::TYPE_PAGE_REFRESH, $request, [
                'visibility' => $request->input('visibility', 'visible'),
            ]);
        }

        if ($deviceSwitched) {
            $this->logEvent($record, ExamEvent::TYPE_DEVICE_SWITCH, $request, [
                'original_device' => $record->device_id,
                'current_device' => $deviceId,
                'resume_reason' => $reason,
                'offline_duration' => $offlineDuration,
            ], $offlineDuration ?: null);
        }

        // 刷新心跳，宽限失效
        $record->update([
            'last_heartbeat_at' => now(),
            'grace_until' => null,
        ]);

        // 超过截止时间+宽限期：学生不可直接续考，等待监考老师决定
        if ($record->deadline_at && now()->greaterThan($record->deadline_at->copy()->addSeconds(self::SUBMIT_GRACE_SECONDS))) {
            if ($record->status === ExamRecord::STATUS_IN_PROGRESS) {
                $record->update([
                    'status' => ExamRecord::STATUS_AWAITING_REVIEW,
                    'review_reason' => ExamRecord::REVIEW_REASON_TIMEOUT,
                ]);
                $this->logEvent($record, ExamEvent::TYPE_OVERDUE, $request, [
                    'offline_total' => $record->offline_total,
                    'device_switched' => $deviceSwitched,
                ]);
            }

            return response()->json([
                'message' => '已超过允许的考试时长（含断网宽限期），请等待监考老师处理',
                'record_status' => ExamRecord::STATUS_AWAITING_REVIEW,
                'review_reason' => $record->review_reason,
                'exam_record' => $record,
            ], 409);
        }

        $response = $this->buildExamResponse($record->fresh(), $examPaper, $deviceSwitched ? '检测到设备变更，已记录但不影响您继续作答' : '已恢复考试现场', 200, $request);
        $data = $response->getData(true);
        $data['device_switched'] = $deviceSwitched;
        $data['offline_duration'] = $offlineDuration;
        $response->setData($data);
        return $response;
    }

    /**
     * 心跳：在线时每 15s 一次。用于服务端判断"最后在线时间"，
     * 与刷新、换设备事件配合甄别真实断网。
     */
    public function heartbeat(Request $request, ExamPaper $examPaper)
    {
        $record = $this->findActiveRecord($request, $examPaper);
        if (!($record instanceof ExamRecord)) {
            return $record; // 错误响应
        }

        $gap = $record->last_heartbeat_at
            ? max(0, (int) $record->last_heartbeat_at->diffInSeconds(now()))
            : 0;

        // 先记录本次心跳时间
        $record->update(['last_heartbeat_at' => now(), 'grace_until' => null]);

        // 心跳缺口异常大：中间大概率发生过真实断网(而不是刷新)。
        // 但 resume 流程可能已经结算过同一缺口——若已有晚于"上一次心跳"的
        // reconnect 事件，则不再重复记账，避免断网时长翻倍。
        if ($gap > self::HEARTBEAT_GAP_SECONDS) {
            $alreadySettled = ExamEvent::where('exam_record_id', $record->id)
                ->where('event_type', ExamEvent::TYPE_RECONNECT)
                ->where('server_ts', '>=', now()->subSeconds($gap + 10))
                ->exists();

            if (!$alreadySettled) {
                $deviceId = (string) $request->input('device_id', '');
                $deviceSwitched = $deviceId !== '' && $record->device_id && $deviceId !== $record->device_id;
                $this->logEvent($record->fresh(), ExamEvent::TYPE_RECONNECT, $request, [
                    'heartbeat_gap' => $gap,
                    'detected_by' => 'heartbeat',
                    'device_switched' => $deviceSwitched,
                ], $gap);
                $record->increment('offline_total', $gap);
            }
        }

        $this->logEvent($record->fresh(), ExamEvent::TYPE_HEARTBEAT, $request, [
            'answered_count' => (int) $request->input('answered_count', 0),
        ]);

        return response()->json([
            'server_time' => now()->getTimestampMs(),
            'deadline_at' => $record->deadline_at?->getTimestampMs(),
            'remaining' => $this->remainingSeconds($record),
            'record_status' => $record->fresh()->status,
        ]);
    }

    /**
     * 通用事件上报（断网开始、页面隐藏/可见等）。
     * 离线期间产生的事件缓存在前端，恢复后随 is_online=0 批量补报。
     */
    public function reportEvents(Request $request, ExamPaper $examPaper)
    {
        $record = $this->findActiveRecord($request, $examPaper);
        if (!($record instanceof ExamRecord)) {
            return $record;
        }

        $validator = Validator::make($request->all(), [
            'events' => 'required|array|min:1|max:50',
            'events.*.type' => 'required|string|max:40',
            'events.*.client_ts' => 'nullable|integer',
            'events.*.is_online' => 'nullable|boolean',
            'events.*.duration' => 'nullable|integer|min:0',
            'events.*.meta' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $allowed = array_keys(ExamEvent::TYPES);
        $rows = [];
        foreach ($request->input('events') as $event) {
            if (!in_array($event['type'], $allowed, true)) {
                continue;
            }
            // 心跳缺口/断网时长不由事件直接累计 offline_total，
            // 统一在 reconnect/resume 结算，避免重复计时
            $rows[] = [
                'exam_record_id' => $record->id,
                'user_id' => $request->user()->id,
                'event_type' => $event['type'],
                'client_ts' => $event['client_ts'] ?? null,
                'server_ts' => now(),
                'device_id' => (string) $request->input('device_id', '') ?: null,
                'is_online' => $event['is_online'] ?? 1,
                'duration' => $event['duration'] ?? null,
                'meta' => isset($event['meta']) ? json_encode($event['meta'], JSON_UNESCAPED_UNICODE) : null,
            ];
        }

        if ($rows) {
            DB::table('exam_events')->insert($rows);
        }

        // 若上报的是断网开始，设置宽限期标记
        $hasOffline = collect($request->input('events'))->contains(fn ($e) => $e['type'] === ExamEvent::TYPE_OFFLINE_DETECTED);
        if ($hasOffline && !$record->grace_until) {
            $record->update([
                'grace_until' => $record->deadline_at
                    ? $record->deadline_at->copy()->addSeconds(self::SUBMIT_GRACE_SECONDS)
                    : now()->addSeconds(self::SUBMIT_GRACE_SECONDS),
            ]);
        }

        return response()->json(['message' => '事件已记录', 'saved' => count($rows)]);
    }

    /**
     * 在线时自动保存/断线恢复后批量同步答案。
     * 未交卷前不判分；同一(记录,题目)以更新时间最新的客户端版本为准。
     */
    public function syncAnswers(Request $request, ExamPaper $examPaper)
    {
        $record = $this->findActiveRecord($request, $examPaper);
        if (!($record instanceof ExamRecord)) {
            return $record;
        }

        $validator = Validator::make($request->all(), [
            'answers' => 'required|array|min:1|max:200',
            'answers.*.question_id' => 'required|exists:questions,id',
            'answers.*.answer' => ['nullable', 'string', 'max:65535'],
            'answers.*.client_updated_at' => 'required|integer',
            'answers.*.status' => 'nullable|string|in:unseen,viewed,answered,marked',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $questionMap = $examPaper->questions()->get()->keyBy('id');
        $saved = 0;
        $versions = [];

        foreach ($request->input('answers') as $item) {
            $questionId = (int) $item['question_id'];
            $question = $questionMap->get($questionId);
            if (!$question) {
                continue; // 防篡改：只接收本试卷的题目
            }

            $answerText = $item['answer'] ?? '';
            if (is_array($answerText)) {
                $answerText = implode(',', $answerText);
            }

            $existing = ExamRecordAnswer::where('exam_record_id', $record->id)
                ->where('question_id', $questionId)
                ->first();

            // 已存在更新的客户端版本时跳过(乱序/重复请求)
            if ($existing && $existing->client_updated_at !== null
                && (int) $existing->client_updated_at >= (int) $item['client_updated_at']) {
                $versions[$questionId] = (int) $existing->client_updated_at;
                continue;
            }

            $meta = ['status' => $item['status'] ?? 'answered'];

            if ($existing) {
                $existing->update([
                    'answer' => $answerText,
                    'client_updated_at' => (int) $item['client_updated_at'],
                    'synced_at' => now(),
                    'meta' => $meta,
                ]);
            } else {
                ExamRecordAnswer::create([
                    'exam_record_id' => $record->id,
                    'question_id' => $questionId,
                    'answer' => $answerText,
                    'is_correct' => false,
                    'score' => 0,
                    'client_updated_at' => (int) $item['client_updated_at'],
                    'synced_at' => now(),
                    'meta' => $meta,
                ]);
            }
            $versions[$questionId] = (int) $item['client_updated_at'];
            $saved++;
        }

        $this->logEvent($record, ExamEvent::TYPE_ANSWER_SYNC, $request, [
            'saved' => $saved,
            'total_synced' => count($versions),
        ]);

        return response()->json([
            'message' => '答案已同步',
            'saved' => $saved,
            'server_time' => now()->getTimestampMs(),
            'versions' => $versions,
        ]);
    }

    public function submit(Request $request, ExamPaper $examPaper)
    {
        $record = ExamRecord::where('exam_paper_id', $examPaper->id)
            ->where('user_id', $request->user()->id)
            ->whereIn('status', [ExamRecord::STATUS_IN_PROGRESS, ExamRecord::STATUS_AWAITING_REVIEW])
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'exam_record_id' => 'required|exists:exam_records,id',
            'answers' => 'present|array',
            'answers.*.question_id' => 'required|exists:questions,id',
            'answers.*.answer' => ['nullable', 'string'],
            'answers.*.client_updated_at' => 'nullable|integer',
            'auto_submit' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // 先落盘全部答案（即使超时转待处理，答案也不丢）
        $questionMap = $examPaper->questions()->get()->keyBy('id');
        $latestByQuestion = ExamRecordAnswer::where('exam_record_id', $record->id)->get()->keyBy('question_id');

        foreach ($request->input('answers', []) as $answerData) {
            $questionId = (int) $answerData['question_id'];
            $question = $questionMap->get($questionId);
            if (!$question) {
                continue;
            }
            $answerText = is_array($answerData['answer'] ?? null)
                ? implode(',', $answerData['answer'])
                : (string) ($answerData['answer'] ?? '');

            $clientTs = $answerData['client_updated_at'] ?? null;
            $existing = $latestByQuestion->get($questionId);

            if ($existing) {
                if ($clientTs === null || (int) $existing->client_updated_at < (int) $clientTs) {
                    $existing->update([
                        'answer' => $answerText,
                        'client_updated_at' => $clientTs ?? $existing->client_updated_at,
                        'synced_at' => now(),
                    ]);
                }
            } else {
                $new = ExamRecordAnswer::create([
                    'exam_record_id' => $record->id,
                    'question_id' => $questionId,
                    'answer' => $answerText,
                    'client_updated_at' => $clientTs,
                    'synced_at' => now(),
                ]);
                $latestByQuestion->put($questionId, $new);
            }
        }

        $autoSubmit = (bool) $request->input('auto_submit', false);
        $overdue = $record->deadline_at
            && now()->greaterThan($record->deadline_at->copy()->addSeconds(self::SUBMIT_GRACE_SECONDS));

        // 超时交卷：不因断网误伤，先存答案，转监考老师决定。
        // 已处于待处理状态的记录同样不能自行交卷绕过监考审批。
        if ($overdue || $record->status === ExamRecord::STATUS_AWAITING_REVIEW) {
            if ($record->status === ExamRecord::STATUS_IN_PROGRESS) {
                $record->update([
                    'status' => ExamRecord::STATUS_AWAITING_REVIEW,
                    'review_reason' => $record->review_reason ?: ExamRecord::REVIEW_REASON_TIMEOUT,
                ]);
                $this->logEvent($record, ExamEvent::TYPE_OVERDUE, $request);
            }
            $this->logEvent($record, $autoSubmit ? ExamEvent::TYPE_AUTO_SUBMIT : ExamEvent::TYPE_SUBMIT, $request, [
                'late_seconds' => $record->deadline_at ? abs((int) $record->deadline_at->diffInSeconds(now())) : null,
                'offline_total' => $record->offline_total,
            ]);

            return response()->json([
                'message' => '检测到交卷时间已超出允许时长（含断网宽限期），答卷已保存，监考老师将根据断网记录决定是否延时或收卷',
                'record_status' => ExamRecord::STATUS_AWAITING_REVIEW,
                'score' => 0,
            ], 202);
        }

        $score = $this->gradeRecord($record, $examPaper);

        $record->update([
            'end_time' => now(),
            'score' => $score,
            'status' => ExamRecord::STATUS_GRADED,
            'grace_until' => null,
        ]);

        $this->logEvent($record, $autoSubmit ? ExamEvent::TYPE_AUTO_SUBMIT : ExamEvent::TYPE_SUBMIT, $request, [
            'score' => $score,
            'offline_total' => $record->offline_total,
        ]);

        return response()->json([
            'message' => '提交成功',
            'score' => $score,
            'exam_record' => $record->load('answers'),
        ]);
    }

    public function myRecords(Request $request)
    {
        $records = ExamRecord::with('examPaper')
            ->where('user_id', $request->user()->id)
            ->orderBy('id', 'desc')
            ->paginate($perPage = $request->input('per_page', 15));

        return response()->json([
            'records' => $records,
        ]);
    }

    public function showRecord(Request $request, ExamRecord $record)
    {
        if ($record->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权查看此记录'], 403);
        }

        $record->load(['examPaper.questions', 'answers.question']);

        return response()->json([
            'record' => $record,
        ]);
    }

    /**
     * 取进行中的考试记录；若已被监考终止/已评分，返回 409 响应。
     */
    protected function findActiveRecord(Request $request, ExamPaper $examPaper)
    {
        $record = ExamRecord::where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->first();

        if (!$record) {
            return response()->json(['message' => '考试记录不存在'], 404);
        }

        if (!in_array($record->status, [ExamRecord::STATUS_IN_PROGRESS, ExamRecord::STATUS_AWAITING_REVIEW], true)) {
            return response()->json([
                'message' => '该场考试已结束',
                'record_status' => $record->status,
            ], 409);
        }

        return $record;
    }

    protected function remainingSeconds(ExamRecord $record): int
    {
        if (!$record->deadline_at) {
            return 0;
        }
        return max(0, (int) now()->diffInSeconds($record->deadline_at, false));
    }

    protected function buildExamResponse(ExamRecord $record, ExamPaper $examPaper, string $message, int $status, Request $request): \Illuminate\Http\JsonResponse
    {
        $questions = $examPaper->questions()->get();

        $questionsData = $questions->map(function ($q) {
            return [
                'id' => $q->id,
                'type' => $q->type,
                'title' => $q->title,
                'options' => $q->options,
                'score' => $q->pivot->score,
            ];
        });

        $savedAnswers = $record->answers()->get()->map(function ($a) {
            return [
                'question_id' => $a->question_id,
                'answer' => $a->answer,
                'client_updated_at' => (int) $a->client_updated_at,
                'synced_at' => $a->synced_at?->getTimestampMs(),
            ];
        });

        return response()->json([
            'message' => $message,
            'exam_record' => [
                'id' => $record->id,
                'status' => $record->status,
                'start_time' => $record->start_time?->getTimestampMs(),
                'deadline_at' => $record->deadline_at?->getTimestampMs(),
                'last_heartbeat_at' => $record->last_heartbeat_at?->getTimestampMs(),
                'offline_total' => (int) $record->offline_total,
                'review_reason' => $record->review_reason,
            ],
            'server_time' => now()->getTimestampMs(),
            'remaining' => $this->remainingSeconds($record),
            'grace_seconds' => self::SUBMIT_GRACE_SECONDS,
            'exam_paper' => [
                'id' => $examPaper->id,
                'title' => $examPaper->title,
                'total_time' => $examPaper->total_time,
                'total_score' => $examPaper->total_score,
            ],
            'questions' => $questionsData,
            'saved_answers' => $savedAnswers,
        ], $status);
    }

    protected function logEvent(ExamRecord $record, string $type, Request $request, array $meta = [], ?int $duration = null): void
    {
        ExamEvent::create([
            'exam_record_id' => $record->id,
            'user_id' => $request->user()->id,
            'event_type' => $type,
            'client_ts' => $request->input('client_ts') !== null ? (int) $request->input('client_ts') : null,
            'server_ts' => now(),
            'device_id' => (string) $request->input('device_id', '') ?: null,
            'is_online' => (bool) $request->input('is_online', true),
            'duration' => $duration,
            'meta' => $meta ?: null,
        ]);
    }

    protected function gradeRecord(ExamRecord $record, ExamPaper $examPaper): float
    {
        $questionMap = $examPaper->questions()->get()->keyBy('id');
        $totalScore = 0;

        $record->answers()->get()->each(function (ExamRecordAnswer $answer) use ($questionMap, &$totalScore) {
            $question = $questionMap->get((int) $answer->question_id);
            if (!$question || $answer->answer === '') {
                $answer->update(['is_correct' => false, 'score' => 0]);
                return;
            }
            $isCorrect = $this->checkAnswer($question, $answer->answer);
            $score = $isCorrect ? (float) $question->pivot->score : 0;
            $answer->update(['is_correct' => $isCorrect, 'score' => $score]);
            $totalScore += $score;
        });

        return $totalScore;
    }

    protected function checkAnswer(Question $question, string $userAnswer): bool
    {
        $correctAnswer = $question->answer;

        switch ($question->type) {
            case 'single_choice':
            case 'true_false':
                return strtoupper(trim($userAnswer)) === strtoupper(trim($correctAnswer));
            case 'multiple_choice':
                $userAnswers = explode(',', strtoupper(trim($userAnswer)));
                $correctAnswers = explode(',', strtoupper(trim($correctAnswer)));
                sort($userAnswers);
                sort($correctAnswers);
                return $userAnswers === $correctAnswers;
            case 'fill_blank':
                return strtoupper(trim($userAnswer)) === strtoupper(trim($correctAnswer));
            default:
                return false;
        }
    }
}
