<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'exam_paper_id',
        'start_time',
        'end_time',
        'score',
        'status',
        'deadline_at',
        'last_heartbeat_at',
        'offline_total',
        'device_id',
        'grace_until',
        'review_reason',
        'reviewed_by',
        'reviewed_at',
        'review_remark',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'exam_paper_id' => 'integer',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'deadline_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'grace_until' => 'datetime',
        'reviewed_at' => 'datetime',
        'score' => 'decimal:2',
        'status' => 'string',
        'offline_total' => 'integer',
        'reviewed_by' => 'integer',
    ];

    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_GRADED = 'graded';
    // 超过截止时间(含宽限期)或设备异常，等待监考老师决定延时/终止
    public const STATUS_AWAITING_REVIEW = 'awaiting_review';
    // 监考老师终止并按现有答案判分
    public const STATUS_TERMINATED = 'terminated';

    public const STATUSES = [
        self::STATUS_IN_PROGRESS => '进行中',
        self::STATUS_SUBMITTED => '已提交',
        self::STATUS_GRADED => '已评分',
        self::STATUS_AWAITING_REVIEW => '待监考处理',
        self::STATUS_TERMINATED => '已终止',
    ];

    public const REVIEW_REASON_TIMEOUT = 'timeout_overdue';
    public const REVIEW_REASON_DEVICE = 'device_switched';

    public const REVIEW_REASONS = [
        self::REVIEW_REASON_TIMEOUT => '超时未交卷',
        self::REVIEW_REASON_DEVICE => '设备变更',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function examPaper()
    {
        return $this->belongsTo(ExamPaper::class, 'exam_paper_id');
    }

    public function answers()
    {
        return $this->hasMany(ExamRecordAnswer::class, 'exam_record_id');
    }

    public function events()
    {
        return $this->hasMany(ExamEvent::class, 'exam_record_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
