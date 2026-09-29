<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamTimeDecision extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_record_id',
        'action',
        'granted_seconds',
        'comment',
        'decided_by',
        'decided_at',
    ];

    protected $casts = [
        'exam_record_id' => 'integer',
        'granted_seconds' => 'integer',
        'decided_by' => 'integer',
        'decided_at' => 'datetime',
    ];

    public const ACTION_GRANT_EXTRA = 'grant_extra';
    public const ACTION_FORCE_SUBMIT = 'force_submit';

    public const ACTION_LABELS = [
        self::ACTION_GRANT_EXTRA => '批准延时',
        self::ACTION_FORCE_SUBMIT => '按时收卷',
    ];

    public function examRecord()
    {
        return $this->belongsTo(ExamRecord::class, 'exam_record_id');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
