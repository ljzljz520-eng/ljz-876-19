<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamConnectionEvent;
use App\Models\ExamPaper;
use App\Models\ExamRecord;
use App\Models\ExamRecordAnswer;
use App\Models\ExamTimeDecision;
use App\Models\Question;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ExamController extends Controller
{
    /** 提交宽限秒数：允许客户端倒计时与服务器的微小误差 */
    private const SUBMIT_GRACE_SECONDS = 120;

    /** 心跳间隔（秒），用于判断丢心跳是否异常 */
    private const HEARTBEAT_INTERVAL = 10;

    /** 连续丢心跳超过该倍数间隔 → 判定为疑似断网 */
    private const HEARTBEAT_MISS_RATIO = 3;

    /** 真实断网超过该秒数记为 warning，超过两倍记为 danger */
    private const OFFLINE_WARNING_SECONDS = 30;

    /** 恢复时距离上次心跳超过该秒数，视为一次真实断网（而非普通刷新） */
    private const RESUME_GAP_WARNING_SECONDS = 30;

    /** 刷新页面允许的最大间隔，超过则按断网风险处理 */
    private const REFRESH_GRACE_SECONDS = 20;

    /** 每 N 次心跳保存一次答案快照 */
    private const SNAPSHOT_EVERY_HEARTBEATS = 2;

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
        $validator = Validator::make($request->all(), [
            'device_id' => 'required|string|max:64',
            'device_label' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $existingRecord = ExamRecord::where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->whereIn('status', [ExamRecord::STATUS_IN_PROGRESS, ExamRecord::STATUS_PENDING_REVIEW])
            ->first();

        $deviceId = $request->input('device_id');
        $deviceLabel = $request->input('device_label');

        if ($existingRecord) {
            // 已有进行中的考试：不在 start 里换设备，引导前端走 resume 续考
            return response()->json([
                'message' => '您有一场未完成的考试，已为您恢复进度',
                'resume' => true,
                'exam_record' => $existingRecord,
            ]);
        }

        $record = ExamRecord::create([
            'user_id' => $request->user()->id,
            'exam_paper_id' => $examPaper->id,
            'start_time' => now(),
            'status' => ExamRecord::STATUS_IN_PROGRESS,
            'device_id' => $deviceId,
            'time_check_status' => ExamRecord::TIME_CHECK_NORMAL,
        ]);

        $this->logEvent($record, ExamConnectionEvent::TYPE_HEARTBEAT, $request, [
            'risk_level' => ExamConnectionEvent::RISK_INFO,
            'reason' => 'start',
            'device_id' => $deviceId,
            'device_label' => $deviceLabel,
        ]);

        return response()->json([
            'message' => '考试开始',
            'resume' => false,
            'exam_record' => $record,
            'exam_paper' => $this->paperData($examPaper),
            'questions' => $this->questionsData($examPaper),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * 续考入口：刷新页面 / 断网恢复 / 换设备后都会调用。
     * 返回服务器权威剩余时间、上次答案快照，供前端合并。
     */
    public function getQuestions(Request $request, ExamPaper $examPaper)
    {
        $record = ExamRecord::where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->whereIn('status', [ExamRecord::STATUS_IN_PROGRESS, ExamRecord::STATUS_PENDING_REVIEW])
            ->firstOrFail();

        $questions = $this->questionsData($examPaper);
        $paperData = $this->paperData($examPaper);
        $time = $this->timeState($record);
        $latestSnapshot = $this->latestAnswerSnapshot($record);

        return response()->json([
            'exam_record' => $record,
            'exam_paper' => $paperData,
            'questions' => $questions,
            'server_time' => now()->toIso8601String(),
            'remaining_seconds' => $time['remaining_seconds'],
            'extra_time_seconds' => (int) $record->extra_time_seconds,
            'expired' => $time['expired'],
            'time_check_status' => $record->time_check_status,
            'latest_snapshot' => $latestSnapshot,
        ]);
    }

    /**
     * 考试保活 / 断网恢复事件接口
     * event: heartbeat（在线心跳，带答案快照）
     *        network_lost（浏览器检测到断网，尽力上报，可能发不出去）
     *        resume（重新连上，真正用于恢复/审计）
     */
    public function ping(Request $request, ExamPaper $examPaper)
    {
        $validator = Validator::make($request->all(), [
            'exam_record_id' => 'required|integer',
            'event' => 'required|in:heartbeat,network_lost,resume',
            'device_id' => 'required|string|max:64',
            'device_label' => 'nullable|string|max:255',
            'client_time' => 'nullable|date',
            'answers' => 'nullable|array',
            'marks' => 'nullable|array',
            'beat' => 'nullable|integer|min:0',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $record = ExamRecord::where('id', $request->exam_record_id)
            ->where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->firstOrFail();

        if (!in_array($record->status, [ExamRecord::STATUS_IN_PROGRESS, ExamRecord::STATUS_PENDING_REVIEW])) {
            return response()->json([
                'message' => '该场考试已结束',
                'closed' => true,
                'score' => $record->score,
            ], 409);
        }

        $event = $request->input('event');
        $deviceId = $request->input('device_id');
        $snapshot = null;
        $beat = (int) $request->input('beat', 0);

        if ($event === ExamConnectionEvent::TYPE_HEARTBEAT) {
            // 换设备期间，旧设备继续上报心跳 → 拒绝并通知旧设备锁定
            if ($record->device_id && $record->device_id !== $deviceId) {
                return response()->json([
                    'message' => '本场考试已在另一台设备继续，本设备已锁定',
                    'device_conflict' => true,
                    'active_device_label' => $this->deviceLabelOf($record),
                ], 409);
            }

            // 按节奏保存答案快照（断网后恢复的“续传”基础）：偶数序号心跳落库，避免事件表膨胀
            if ($beat > 0 && $beat % self::SNAPSHOT_EVERY_HEARTBEATS === 0) {
                $snapshot = $this->buildSnapshot($request);
            }

            $lastEvent = $this->lastHeartbeatOrResume($record);
            $risk = ExamConnectionEvent::RISK_INFO;
            if ($lastEvent) {
                $gap = $lastEvent->server_time->diffInSeconds(now());
                if ($gap > self::HEARTBEAT_INTERVAL * self::HEARTBEAT_MISS_RATIO) {
                    $risk = ExamConnectionEvent::RISK_WARNING;
                }
            }

            $this->logEvent($record, ExamConnectionEvent::TYPE_HEARTBEAT, $request, [
                'risk_level' => $risk,
                'answer_snapshot' => $snapshot,
                'device_id' => $deviceId,
            ]);

            return $this->pingResponse($record);
        }

        if ($event === ExamConnectionEvent::TYPE_NETWORK_LOST) {
            // 前端检测到 navigator offline 时尽力上报，发不出去也不影响，恢复时仍可由 gap 兜底判定
            $this->logEvent($record, ExamConnectionEvent::TYPE_NETWORK_LOST, $request, [
                'risk_level' => ExamConnectionEvent::RISK_INFO,
                'reason' => ExamConnectionEvent::REASON_OFFLINE,
                'answer_snapshot' => $this->buildSnapshot($request),
                'device_id' => $deviceId,
            ]);

            return response()->json(['ok' => true, 'server_time' => now()->toIso8601String()]);
        }

        // ===== event = resume：重新连上 / 刷新后回来 / 换设备 =====
        $lastEvent = $this->lastHeartbeatOrResume($record);
        $openLost = $record->connectionEvents()
            ->where('event_type', ExamConnectionEvent::TYPE_NETWORK_LOST)
            ->whereNull('recovered_at')
            ->latest('server_time')
            ->first();

        $switched = $record->device_id && $record->device_id !== $deviceId;
        $gap = $lastEvent ? $lastEvent->server_time->diffInSeconds(now()) : null;

        $risk = ExamConnectionEvent::RISK_INFO;
        $offlineSeconds = null;

        if ($openLost) {
            // 有明确的断网上报：真实断网
            $offlineSeconds = max(0, $openLost->server_time->diffInSeconds(now()));
            $openLost->update([
                'recovered_at' => now(),
                'offline_seconds' => $offlineSeconds,
                'risk_level' => $offlineSeconds > self::OFFLINE_WARNING_SECONDS
                    ? ExamConnectionEvent::RISK_WARNING
                    : ExamConnectionEvent::RISK_INFO,
            ]);
            if ($offlineSeconds > self::OFFLINE_WARNING_SECONDS) {
                $risk = ExamConnectionEvent::RISK_WARNING;
            }
        } elseif ($gap !== null && $gap > self::RESUME_GAP_WARNING_SECONDS) {
            // 没有断网上报、但心跳消失很久：按真实断网对待（网络彻底断开时上报通常发不出来）
            $offlineSeconds = $gap;
            $risk = ExamConnectionEvent::RISK_WARNING;
        }

        $switchCount = 0;
        if ($switched) {
            // 换设备：单独记录，风险随换设备次数升高，但绝不自动按作弊处理
            $switchCount = $record->connectionEvents()
                ->where('event_type', ExamConnectionEvent::TYPE_DEVICE_SWITCH)
                ->count() + 1;
            $this->logEvent($record, ExamConnectionEvent::TYPE_DEVICE_SWITCH, $request, [
                'risk_level' => $switchCount >= 3 ? ExamConnectionEvent::RISK_DANGER
                    : ($switchCount >= 2 ? ExamConnectionEvent::RISK_WARNING : ExamConnectionEvent::RISK_INFO),
                'reason' => 'resume_on_new_device',
                'answer_snapshot' => $this->buildSnapshot($request),
                'device_id' => $deviceId,
            ]);
        }

        $this->logEvent($record, ExamConnectionEvent::TYPE_RESUME, $request, [
            'risk_level' => $risk,
            'reason' => $openLost || ($gap !== null && $gap > self::REFRESH_GRACE_SECONDS)
                ? ExamConnectionEvent::REASON_OFFLINE
                : ExamConnectionEvent::REASON_REFRESH,
            'answer_snapshot' => $this->buildSnapshot($request),
            'offline_seconds' => $offlineSeconds,
            'recovered_at' => $openLost ? now() : null,
            'device_id' => $deviceId,
        ]);

        if ($switched) {
            $record->device_id = $deviceId;
            $record->save();
        }

        return $this->pingResponse($record, [
            'resumed' => true,
            'switched_device' => $switched,
            'offline_seconds' => $offlineSeconds,
        ]);
    }

    /**
     * 页面卸载（刷新 / 关闭 / 跳转）时用 fetch keepalive 上报。
     * 与真实断网区分：带 reason=refresh 的是学生主动离开页面。
     */
    public function leaveBeacon(Request $request, ExamPaper $examPaper)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'exam_record_id' => 'required|integer',
            'device_id' => 'required|string|max:64',
            'device_label' => 'nullable|string|max:255',
            'client_time' => 'nullable|date',
            'answers' => 'nullable|array',
            'marks' => 'nullable|array',
        ]);
        if ($validator->fails()) {
            return response()->json(['ok' => false], 422);
        }

        $token = \Laravel\Sanctum\PersonalAccessToken::findToken($request->input('token'));
        if (!$token) {
            return response()->json(['ok' => false], 401);
        }
        $user = $token->tokenable;

        $record = ExamRecord::where('id', $request->exam_record_id)
            ->where('user_id', $user->id)
            ->where('exam_paper_id', $examPaper->id)
            ->first();
        if (!$record || !in_array($record->status, [ExamRecord::STATUS_IN_PROGRESS, ExamRecord::STATUS_PENDING_REVIEW])) {
            return response()->json(['ok' => true]);
        }

        // 换设备后的旧标签页关闭，不算异常
        if ($record->device_id && $record->device_id !== $request->input('device_id')) {
            return response()->json(['ok' => true]);
        }

        // 同一秒重复的卸载事件去重（浏览器 pagehide/beforeunload 可能都触发）
        $exists = $record->connectionEvents()
            ->where('event_type', ExamConnectionEvent::TYPE_PAGE_LEAVE)
            ->where('server_time', '>=', now()->subSeconds(3))
            ->exists();
        if (!$exists) {
            // 临时把 token 对应的用户挂到 request 上，复用统一日志方法
            $request->setUserResolver(fn () => $user);
            $this->logEvent($record, ExamConnectionEvent::TYPE_PAGE_LEAVE, $request, [
                'risk_level' => ExamConnectionEvent::RISK_INFO,
                'reason' => ExamConnectionEvent::REASON_REFRESH,
                'answer_snapshot' => $this->buildSnapshot($request),
                'device_id' => $request->input('device_id'),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    public function submit(Request $request, ExamPaper $examPaper)
    {
        $validator = Validator::make($request->all(), [
            'exam_record_id' => 'required|exists:exam_records,id',
            'answers' => 'array',
            'answers.*.question_id' => 'required|exists:questions,id',
            'answers.*.answer' => 'string',
            'device_id' => 'nullable|string|max:64',
            'client_time' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $record = ExamRecord::where('id', $request->exam_record_id)
            ->where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->whereIn('status', [ExamRecord::STATUS_IN_PROGRESS, ExamRecord::STATUS_PENDING_REVIEW])
            ->firstOrFail();

        $time = $this->timeState($record);
        $overtime = $time['overtime_seconds'] > self::SUBMIT_GRACE_SECONDS;

        // 已被老师批准延时但仍超时，或已进入审核流程后再次超时：继续按待审核处理
        if ($overtime && $record->time_check_status !== ExamRecord::TIME_CHECK_APPROVED) {
            // 先把答案原样保存（不评分），等监考老师决定延时还是收卷
            $this->storeRawAnswers($record, $request->answers ?? []);
            $record->update([
                'end_time' => now(),
                'status' => ExamRecord::STATUS_PENDING_REVIEW,
                'time_check_status' => ExamRecord::TIME_CHECK_PENDING,
            ]);

            $request->merge(['answers' => $request->answers ?? []]);
            $this->logEvent($record, ExamConnectionEvent::TYPE_OVERTIME_SUBMIT, $request, [
                'risk_level' => ExamConnectionEvent::RISK_WARNING,
                'reason' => 'overtime',
                'answer_snapshot' => $this->buildSnapshot($request),
                'device_id' => $request->input('device_id'),
            ]);

            return response()->json([
                'message' => '已超过允许考试时长，你的答案已保存，监考老师将决定是否延时，请等待审核结果',
                'pending_review' => true,
                'overtime_seconds' => $time['overtime_seconds'],
            ], 202);
        }

        // 批准延时后又超时：同样转审核，由老师决定追加延时或收卷
        if ($overtime && $record->time_check_status === ExamRecord::TIME_CHECK_APPROVED) {
            $this->storeRawAnswers($record, $request->answers ?? []);
            $record->update([
                'end_time' => now(),
                'status' => ExamRecord::STATUS_PENDING_REVIEW,
                'time_check_status' => ExamRecord::TIME_CHECK_PENDING,
            ]);
            return response()->json([
                'message' => '批准的延时已用完，答案已保存，等待监考老师处理',
                'pending_review' => true,
                'overtime_seconds' => $time['overtime_seconds'],
            ], 202);
        }

        $totalScore = $this->gradeAnswers($record, $examPaper, $request->answers ?? []);

        $record->update([
            'end_time' => now(),
            'score' => $totalScore,
            'status' => ExamRecord::STATUS_GRADED,
            'time_check_status' => $record->time_check_status === ExamRecord::TIME_CHECK_PENDING
                ? ExamRecord::TIME_CHECK_APPROVED
                : ExamRecord::TIME_CHECK_NORMAL,
        ]);

        return response()->json([
            'message' => '提交成功',
            'score' => $totalScore,
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

    // ============================ 监考端 ============================

    /**
     * 监考列表：进行中 / 待审核的考试，含断网/刷新/换设备事件统计
     */
    public function monitorIndex(Request $request)
    {
        $query = ExamRecord::with(['user', 'examPaper'])
            ->whereIn('status', [
                ExamRecord::STATUS_IN_PROGRESS,
                ExamRecord::STATUS_PENDING_REVIEW,
            ]);

        // 教师只能看自己创建的试卷；管理员可看全部
        if (!$request->user()->isAdmin()) {
            $query->whereHas('examPaper', function ($q) use ($request) {
                $q->where('created_by', $request->user()->id);
            });
        }

        if ($filter = $request->input('status')) {
            $query->where('status', $filter);
        }
        if ($request->input('only_pending') == 1) {
            $query->where('time_check_status', ExamRecord::TIME_CHECK_PENDING);
        }

        $records = $query->with(['connectionEvents'])
            ->orderByDesc('id')
            ->paginate($request->input('per_page', 15));

        $records->getCollection()->transform(function ($record) {
            $events = $record->connectionEvents;
            $time = $this->timeState($record);

            return [
                'id' => $record->id,
                'user_id' => $record->user_id,
                'exam_paper_id' => $record->exam_paper_id,
                'start_time' => $record->start_time?->toDateTimeString(),
                'end_time' => $record->end_time?->toDateTimeString(),
                'score' => $record->score,
                'status' => $record->status,
                'time_check_status' => $record->time_check_status,
                'extra_time_seconds' => (int) $record->extra_time_seconds,
                'user' => $record->user,
                'exam_paper' => $record->examPaper,
                'remaining_seconds' => $time['remaining_seconds'],
                'overtime_seconds' => $time['overtime_seconds'],
                'event_summary' => [
                    'offline_count' => $events->where('event_type', ExamConnectionEvent::TYPE_NETWORK_LOST)->count(),
                    'refresh_count' => $events->where('event_type', ExamConnectionEvent::TYPE_PAGE_LEAVE)->count(),
                    'device_switch_count' => $events->where('event_type', ExamConnectionEvent::TYPE_DEVICE_SWITCH)->count(),
                    'total_offline_seconds' => (int) $events->sum('offline_seconds'),
                    'has_danger' => $events->contains('risk_level', ExamConnectionEvent::RISK_DANGER),
                ],
            ];
        });

        return response()->json(['records' => $records]);
    }

    /** 监考详情：考试信息 + 完整事件链 + 最新答案快照 */
    public function monitorShow(Request $request, ExamRecord $record)
    {
        $this->authorizeMonitor($request, $record);

        $record->load(['user', 'examPaper', 'timeDecisions.decider']);

        $events = $record->connectionEvents()
            ->orderBy('server_time')
            ->get()
            ->map(function ($e) {
                return [
                    'id' => $e->id,
                    'event_type' => $e->event_type,
                    'event_label' => ExamConnectionEvent::EVENT_LABELS[$e->event_type] ?? $e->event_type,
                    'reason' => $e->reason,
                    'risk_level' => $e->risk_level,
                    'risk_label' => ExamConnectionEvent::RISK_LABELS[$e->risk_level] ?? $e->risk_level,
                    'device_id' => $e->device_id,
                    'device_label' => $e->device_label,
                    'ip_address' => $e->ip_address,
                    'client_time' => $e->client_time?->toDateTimeString(),
                    'server_time' => $e->server_time->toDateTimeString(),
                    'offline_seconds' => $e->offline_seconds,
                    'recovered_at' => $e->recovered_at?->toDateTimeString(),
                    'answer_snapshot' => $e->answer_snapshot,
                ];
            });

        $time = $this->timeState($record);

        $questions = $record->examPaper->questions()->get()->map(fn ($q) => [
            'id' => $q->id,
            'type' => $q->type,
            'title' => $q->title,
        ])->values();

        return response()->json([
            'record' => $record,
            'questions' => $questions,
            'remaining_seconds' => $time['remaining_seconds'],
            'overtime_seconds' => $time['overtime_seconds'],
            'latest_snapshot' => $this->latestAnswerSnapshot($record),
            'events' => $events,
        ]);
    }

    /**
     * 监考决定：
     *  - grant_extra：批准延长 granted_seconds 秒，学生继续作答
     *  - force_submit：拒绝延时，按最近一次答案快照立即收卷评分
     */
    public function monitorDecision(Request $request, ExamRecord $record)
    {
        $this->authorizeMonitor($request, $record);

        $validator = Validator::make($request->all(), [
            'action' => 'required|in:grant_extra,force_submit',
            'granted_seconds' => 'required_if:action,grant_extra|integer|min:60|max:14400',
            'comment' => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $action = $request->input('action');

        if ($action === ExamTimeDecision::ACTION_GRANT_EXTRA) {
            $seconds = (int) $request->input('granted_seconds');

            $record->extra_time_seconds = (int) $record->extra_time_seconds + $seconds;
            $record->status = ExamRecord::STATUS_IN_PROGRESS;
            $record->time_check_status = ExamRecord::TIME_CHECK_APPROVED;
            $record->end_time = null;
            $record->save();

            ExamTimeDecision::create([
                'exam_record_id' => $record->id,
                'action' => ExamTimeDecision::ACTION_GRANT_EXTRA,
                'granted_seconds' => $seconds,
                'comment' => $request->input('comment'),
                'decided_by' => $request->user()->id,
                'decided_at' => now(),
            ]);

            return response()->json([
                'message' => "已批准延时 {$seconds} 秒，学生可继续作答",
                'extra_time_seconds' => (int) $record->extra_time_seconds,
                'remaining_seconds' => $this->timeState($record)['remaining_seconds'],
            ]);
        }

        // force_submit：以最近一次答案快照（心跳/续考/超时提交）收卷评分
        $snapshot = $this->latestAnswerSnapshot($record);
        $answers = [];
        if ($snapshot && !empty($snapshot['answers'])) {
            foreach ($snapshot['answers'] as $questionId => $answer) {
                if ($answer === null || $answer === '') {
                    continue;
                }
                $answers[] = [
                    'question_id' => (int) $questionId,
                    'answer' => is_array($answer) ? implode(',', $answer) : (string) $answer,
                ];
            }
        }

        $score = $this->gradeAnswers($record, $record->examPaper, $answers);
        $record->update([
            'end_time' => now(),
            'score' => $score,
            'status' => ExamRecord::STATUS_GRADED,
            'time_check_status' => ExamRecord::TIME_CHECK_DENIED,
        ]);

        ExamTimeDecision::create([
            'exam_record_id' => $record->id,
            'action' => ExamTimeDecision::ACTION_FORCE_SUBMIT,
            'granted_seconds' => 0,
            'comment' => $request->input('comment'),
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
        ]);

        return response()->json([
            'message' => '已按时收卷并完成评分',
            'score' => $score,
        ]);
    }

    // ============================ 内部辅助 ============================

    private function authorizeMonitor(Request $request, ExamRecord $record): void
    {
        $user = $request->user();
        if ($user->isAdmin()) {
            return;
        }
        if ($user->isTeacher() && $record->examPaper && $record->examPaper->created_by == $user->id) {
            return;
        }
        abort(403, '无权操作此考试记录');
    }

    private function paperData(ExamPaper $examPaper): array
    {
        return [
            'id' => $examPaper->id,
            'title' => $examPaper->title,
            'total_time' => $examPaper->total_time,
            'total_score' => $examPaper->total_score,
        ];
    }

    private function questionsData(ExamPaper $examPaper)
    {
        return $examPaper->questions()->get()->map(function ($q) {
            return [
                'id' => $q->id,
                'type' => $q->type,
                'title' => $q->title,
                'options' => $q->options,
                'score' => $q->pivot->score,
            ];
        });
    }

    /**
     * 服务器权威时间状态。
     * 剩余时间 = 开考时刻 + 试卷时长 + 批准延时 - 当前服务器时间
     */
    private function timeState(ExamRecord $record): array
    {
        $paper = $record->examPaper;
        $allowedSeconds = $paper->total_time * 60 + (int) $record->extra_time_seconds;
        $deadline = $record->start_time->copy()->addSeconds($allowedSeconds);
        $remaining = $deadline->diffInSeconds(now(), false);

        return [
            'remaining_seconds' => max(0, (int) $remaining),
            'overtime_seconds' => $remaining < 0 ? (int) -$remaining : 0,
            'deadline' => $deadline->toIso8601String(),
            'expired' => $remaining <= 0,
        ];
    }

    private function lastHeartbeatOrResume(ExamRecord $record): ?ExamConnectionEvent
    {
        return $record->connectionEvents()
            ->whereIn('event_type', [ExamConnectionEvent::TYPE_HEARTBEAT, ExamConnectionEvent::TYPE_RESUME])
            ->latest('server_time')
            ->first();
    }

    private function latestAnswerSnapshot(ExamRecord $record): ?array
    {
        $event = $record->connectionEvents()
            ->whereNotNull('answer_snapshot')
            ->latest('server_time')
            ->first();

        return $event?->answer_snapshot;
    }

    private function buildSnapshot(Request $request): array
    {
        return [
            'answers' => $request->input('answers') ?? new \stdClass(),
            'marks' => $request->input('marks') ?? new \stdClass(),
            'client_time' => $request->input('client_time') ?? now()->toIso8601String(),
        ];
    }

    private function logEvent(ExamRecord $record, string $type, Request $request, array $override = []): ExamConnectionEvent
    {
        $data = array_merge([
            'exam_record_id' => $record->id,
            'event_type' => $type,
            'device_id' => $request->input('device_id'),
            'device_label' => $request->input('device_label') ?: $this->parseDeviceLabel($request->userAgent()),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'client_time' => $request->input('client_time') ? Carbon::parse($request->input('client_time')) : null,
            'server_time' => now(),
            'risk_level' => ExamConnectionEvent::RISK_INFO,
        ], $override);

        return ExamConnectionEvent::create($data);
    }

    private function parseDeviceLabel(?string $userAgent): string
    {
        $ua = (string) $userAgent;
        $os = '未知系统';
        if (preg_match('/Windows NT 10/', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/Android/', $ua)) {
            $os = 'Android';
        } elseif (preg_match('/iPhone|iPad|iPod/', $ua)) {
            $os = 'iOS';
        } elseif (preg_match('/Mac OS X/', $ua)) {
            $os = 'macOS';
        } elseif (preg_match('/Linux/', $ua)) {
            $os = 'Linux';
        }

        $browser = '未知浏览器';
        if (preg_match('/Edg\//', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('/Chrome\//', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Firefox\//', $ua)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Safari\//', $ua)) {
            $browser = 'Safari';
        }

        return $os . ' / ' . $browser;
    }

    private function deviceLabelOf(ExamRecord $record): ?string
    {
        return $record->connectionEvents()
            ->where('device_id', $record->device_id)
            ->whereNotNull('device_label')
            ->latest('server_time')
            ->value('device_label');
    }

    private function pingResponse(ExamRecord $record, array $extra = [])
    {
        $time = $this->timeState($record);

        return response()->json(array_merge([
            'ok' => true,
            'server_time' => now()->toIso8601String(),
            'remaining_seconds' => $time['remaining_seconds'],
            'overtime_seconds' => $time['overtime_seconds'],
            'expired' => $time['expired'],
            'time_check_status' => $record->time_check_status,
            'status' => $record->status,
        ], $extra));
    }

    /**
     * 保存原始答案但不评分（超时待审核场景）。
     */
    private function storeRawAnswers(ExamRecord $record, array $answers): void
    {
        $record->answers()->delete();

        foreach ($answers as $answerData) {
            $answer = $answerData['answer'] ?? '';
            if ($answer === '' || $answer === null) {
                continue;
            }
            ExamRecordAnswer::create([
                'exam_record_id' => $record->id,
                'question_id' => $answerData['question_id'],
                'answer' => $answer,
                'is_correct' => false,
                'score' => 0,
            ]);
        }
    }

    /**
     * 评分并写入答案。支持教师“按时收卷”时对快照答案评分。
     */
    private function gradeAnswers(ExamRecord $record, ExamPaper $examPaper, array $answers): float
    {
        $record->answers()->delete();
        $examPaper->loadMissing('questions');
        $questionMap = $examPaper->questions->keyBy('id');

        $totalScore = 0;
        foreach ($answers as $answerData) {
            $question = $questionMap->get($answerData['question_id']);
            if (!$question) {
                continue;
            }

            $answer = (string) ($answerData['answer'] ?? '');
            if ($answer === '') {
                continue;
            }

            $isCorrect = $this->checkAnswer($question, $answer);
            $score = $isCorrect ? $question->pivot->score : 0;

            ExamRecordAnswer::create([
                'exam_record_id' => $record->id,
                'question_id' => $answerData['question_id'],
                'answer' => $answer,
                'is_correct' => $isCorrect,
                'score' => $score,
            ]);

            $totalScore += $score;
        }

        return (float) $totalScore;
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
