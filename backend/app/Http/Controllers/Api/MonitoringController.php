<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamEvent;
use App\Models\ExamPaper;
use App\Models\ExamRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MonitoringController extends Controller
{
    /**
     * 监考列表：进行中 / 待处理的考试，附带断网、刷新、换设备甄别摘要。
     * 后台区分三类情况，而不是一律按作弊处理：
     *  - 真实断网：offline_detected -> reconnect 成对，且心跳缺口与离线时长吻合
     *  - 刷新页面：page_refresh 事件、设备指纹不变、心跳缺口通常 < 60s
     *  - 换设备登录：device_switch 事件、device_id 变化
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isTeacher()) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $query = ExamRecord::with(['user:id,username,real_name', 'examPaper:id,title,total_time,created_by'])
            ->withCount('answers')
            ->whereIn('status', [
                ExamRecord::STATUS_IN_PROGRESS,
                ExamRecord::STATUS_AWAITING_REVIEW,
            ]);

        // 非管理员教师只看自己创建的试卷
        if (!$user->isAdmin()) {
            $paperIds = ExamPaper::where('created_by', $user->id)->pluck('id');
            $query->whereIn('exam_paper_id', $paperIds);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('exam_paper_id')) {
            $query->where('exam_paper_id', (int) $request->input('exam_paper_id'));
        }

        $records = $query->orderByRaw("FIELD(status, 'awaiting_review', 'in_progress')")
            ->orderBy('deadline_at')
            ->paginate((int) $request->input('per_page', 20));

        $recordIds = $records->pluck('id');

        // 一次性聚合事件，避免 N+1
        $eventCounts = ExamEvent::whereIn('exam_record_id', $recordIds)
            ->selectRaw('exam_record_id, event_type, COUNT(*) as c, MAX(server_ts) as last_at, SUM(COALESCE(duration,0)) as total_duration')
            ->groupBy('exam_record_id', 'event_type')
            ->get()
            ->groupBy('exam_record_id');

        $records->getCollection()->transform(function ($record) use ($eventCounts) {
            $counts = $eventCounts->get($record->id, collect());
            $map = [];
            foreach ($counts as $row) {
                $map[$row->event_type] = [
                    'count' => (int) $row->c,
                    'last_at' => $row->last_at,
                    'total_duration' => (int) $row->total_duration,
                ];
            }

            return [
                'id' => $record->id,
                'user' => $record->user,
                'exam_paper' => $record->examPaper,
                'status' => $record->status,
                'start_time' => $record->start_time,
                'deadline_at' => $record->deadline_at,
                'last_heartbeat_at' => $record->last_heartbeat_at,
                'offline_total' => (int) $record->offline_total,
                'grace_until' => $record->grace_until,
                'review_reason' => $record->review_reason,
                'device_id' => $record->device_id,
                'is_overdue' => $record->deadline_at && now()->greaterThan($record->deadline_at),
                'answered_count' => (int) ($record->answers_count ?? 0),
                'classification' => $this->classify($record, $map),
                'event_summary' => [
                    'offline' => $map['offline_detected']['count'] ?? 0,
                    'reconnect' => $map['reconnect']['count'] ?? 0,
                    'page_refresh' => $map['page_refresh']['count'] ?? 0,
                    'page_hidden' => $map['page_hidden']['count'] ?? 0,
                    'device_switch' => $map['device_switch']['count'] ?? 0,
                    'offline_duration' => $map['reconnect']['total_duration'] ?? 0,
                ],
            ];
        });

        return response()->json(['records' => $records]);
    }

    /**
     * 某场考试的完整事件时间线，供监考老师人工研判。
     */
    public function show(Request $request, ExamRecord $record)
    {
        if (!$this->canManage($request->user(), $record)) {
            return response()->json(['message' => '无权查看该考试'], 403);
        }

        $record->load(['user:id,username,real_name', 'examPaper', 'answers.question:id,title,type', 'reviewer:id,username,real_name']);

        $events = $record->events()->orderBy('id')->get()->map(function ($e) {
            return [
                'id' => $e->id,
                'type' => $e->event_type,
                'type_label' => ExamEvent::TYPES[$e->event_type] ?? $e->event_type,
                'client_ts' => $e->client_ts,
                'server_ts' => $e->server_ts,
                'device_id' => $e->device_id,
                'is_online' => $e->is_online,
                'duration' => $e->duration,
                'meta' => $e->meta,
            ];
        });

        return response()->json([
            'record' => [
                'id' => $record->id,
                'status' => $record->status,
                'review_reason' => $record->review_reason,
                'review_remark' => $record->review_remark,
                'reviewer' => $record->reviewer,
                'reviewed_at' => $record->reviewed_at,
                'start_time' => $record->start_time,
                'deadline_at' => $record->deadline_at,
                'end_time' => $record->end_time,
                'last_heartbeat_at' => $record->last_heartbeat_at,
                'offline_total' => (int) $record->offline_total,
                'score' => $record->score,
                'user' => $record->user,
                'exam_paper' => $record->examPaper,
                'answers' => $record->answers,
            ],
            'classification' => $this->classify(
                $record,
                $record->events->groupBy('event_type')->map(fn ($g) => ['count' => $g->count(), 'total_duration' => (int) $g->sum('duration')])->all()
            ),
            'events' => $events,
        ]);
    }

    /**
     * 批准延时：将截止时间延后，学生可继续作答。
     */
    public function extend(Request $request, ExamRecord $record)
    {
        if (!$this->canManage($request->user(), $record)) {
            return response()->json(['message' => '无权处理该考试'], 403);
        }

        $validator = Validator::make($request->all(), [
            'extra_minutes' => 'required|integer|min:1|max:300',
            'remark' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (!in_array($record->status, [ExamRecord::STATUS_IN_PROGRESS, ExamRecord::STATUS_AWAITING_REVIEW], true)) {
            return response()->json(['message' => '该考试已结束，无法延时'], 409);
        }

        $extra = (int) $request->input('extra_minutes');
        // 以“当前时间/原截止时间”中较晚者为基准顺延
        $base = $record->deadline_at && $record->deadline_at->greaterThan(now())
            ? $record->deadline_at
            : now();

        $record->update([
            'deadline_at' => $base->copy()->addMinutes($extra),
            'status' => ExamRecord::STATUS_IN_PROGRESS,
            'review_reason' => null,
            'grace_until' => null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_remark' => $request->input('remark'),
        ]);

        ExamEvent::create([
            'exam_record_id' => $record->id,
            'user_id' => $record->user_id,
            'event_type' => ExamEvent::TYPE_REVIEW_EXTEND,
            'server_ts' => now(),
            'meta' => [
                'extra_minutes' => $extra,
                'reviewer' => $request->user()->username,
                'remark' => $request->input('remark'),
            ],
        ]);

        return response()->json([
            'message' => "已批准延时 {$extra} 分钟",
            'deadline_at' => $record->deadline_at->getTimestampMs(),
        ]);
    }

    /**
     * 终止考试：按当前已保存的答卷判分。
     * 用于监考老师认定不合理（如换设备作弊、超时且无断网记录）的情况。
     */
    public function terminate(Request $request, ExamRecord $record)
    {
        if (!$this->canManage($request->user(), $record)) {
            return response()->json(['message' => '无权处理该考试'], 403);
        }

        $validator = Validator::make($request->all(), [
            'remark' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (!in_array($record->status, [ExamRecord::STATUS_IN_PROGRESS, ExamRecord::STATUS_AWAITING_REVIEW], true)) {
            return response()->json(['message' => '该考试已结束'], 409);
        }

        $score = $this->gradeWithPaper($record);

        $record->update([
            'end_time' => now(),
            'score' => $score,
            'status' => ExamRecord::STATUS_TERMINATED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_remark' => $request->input('remark'),
            'grace_until' => null,
        ]);

        ExamEvent::create([
            'exam_record_id' => $record->id,
            'user_id' => $record->user_id,
            'event_type' => ExamEvent::TYPE_REVIEW_TERMINATE,
            'server_ts' => now(),
            'meta' => [
                'score' => $score,
                'reviewer' => $request->user()->username,
                'remark' => $request->input('remark'),
            ],
        ]);

        return response()->json(['message' => '考试已终止并按现有答卷判分', 'score' => $score]);
    }

    /**
     * 综合事件给出系统初判（仅为监考老师提供参考，不自动定罪）：
     *  - network_outage 真实断网
     *  - page_refresh 刷新页面
     *  - device_switch 换设备登录
     *  - normal 正常进行
     *  - suspicious 存在无法用断网解释的异常
     */
    protected function classify(ExamRecord $record, array $eventMap): string
    {
        $offline = $eventMap['offline_detected']['count'] ?? 0;
        $reconnect = $eventMap['reconnect']['count'] ?? 0;
        $refresh = $eventMap['page_refresh']['count'] ?? 0;
        $switch = $eventMap['device_switch']['count'] ?? 0;
        $reconnectDuration = $eventMap['reconnect']['total_duration'] ?? 0;

        if ($switch > 0) {
            return 'device_switch';
        }

        if ($offline > 0 || ($reconnect > 0 && $reconnectDuration > 60)) {
            return 'network_outage';
        }

        if ($refresh > 0) {
            return 'page_refresh';
        }

        // 心跳已超时且没有任何断网/刷新事件解释
        if ($record->status === ExamRecord::STATUS_AWAITING_REVIEW
            || ($record->last_heartbeat_at && abs($record->last_heartbeat_at->diffInSeconds(now())) > 120)) {
            return 'suspicious';
        }

        return 'normal';
    }

    protected function canManage($user, ExamRecord $record): bool
    {
        if (!$user->isAdmin() && !$user->isTeacher()) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }
        return optional($record->examPaper)->created_by == $user->id;
    }

    protected function gradeWithPaper(ExamRecord $record): float
    {
        $paper = $record->examPaper()->with('questions')->first();
        $questionMap = $paper->questions->keyBy('id');
        $total = 0.0;

        foreach ($record->answers()->get() as $answer) {
            $question = $questionMap->get((int) $answer->question_id);
            if (!$question || trim((string) $answer->answer) === '') {
                $answer->update(['is_correct' => false, 'score' => 0]);
                continue;
            }
            $correct = $this->check($question->type, $question->answer, $answer->answer);
            $score = $correct ? (float) $question->pivot->score : 0;
            $answer->update(['is_correct' => $correct, 'score' => $score]);
            $total += $score;
        }

        return $total;
    }

    protected function check(string $type, string $correctAnswer, string $userAnswer): bool
    {
        switch ($type) {
            case 'single_choice':
            case 'true_false':
            case 'fill_blank':
                return strtoupper(trim($userAnswer)) === strtoupper(trim($correctAnswer));
            case 'multiple_choice':
                $u = explode(',', strtoupper(trim($userAnswer)));
                $c = explode(',', strtoupper(trim($correctAnswer)));
                sort($u);
                sort($c);
                return $u === $c;
            default:
                return false;
        }
    }
}
