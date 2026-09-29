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
        'device_id',
        'extra_time_seconds',
        'time_check_status',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'exam_paper_id' => 'integer',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'score' => 'decimal:2',
        'status' => 'string',
        'extra_time_seconds' => 'integer',
        'time_check_status' => 'string',
    ];

    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_GRADED = 'graded';
    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUSES = [
        self::STATUS_IN_PROGRESS => '进行中',
        self::STATUS_SUBMITTED => '已提交',
        self::STATUS_GRADED => '已评分',
        self::STATUS_PENDING_REVIEW => '超时待审核',
    ];

    // 超时审核状态
    public const TIME_CHECK_NORMAL = 'normal';
    public const TIME_CHECK_PENDING = 'pending_review';
    public const TIME_CHECK_APPROVED = 'approved';
    public const TIME_CHECK_DENIED = 'denied';

    public const TIME_CHECK_LABELS = [
        self::TIME_CHECK_NORMAL => '正常',
        self::TIME_CHECK_PENDING => '待监考审核',
        self::TIME_CHECK_APPROVED => '已批准延时',
        self::TIME_CHECK_DENIED => '已按时收卷',
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

    public function connectionEvents()
    {
        return $this->hasMany(ExamConnectionEvent::class, 'exam_record_id');
    }

    public function timeDecisions()
    {
        return $this->hasMany(ExamTimeDecision::class, 'exam_record_id');
    }

    public function latestDecision()
    {
        return $this->hasOne(ExamTimeDecision::class, 'exam_record_id')->latestOfMany();
    }
}
