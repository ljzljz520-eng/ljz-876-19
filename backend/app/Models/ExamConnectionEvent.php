<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamConnectionEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_record_id',
        'event_type',
        'device_id',
        'device_label',
        'ip_address',
        'user_agent',
        'client_time',
        'server_time',
        'reason',
        'risk_level',
        'answer_snapshot',
        'recovered_at',
        'offline_seconds',
    ];

    protected $casts = [
        'exam_record_id' => 'integer',
        'client_time' => 'datetime',
        'server_time' => 'datetime',
        'recovered_at' => 'datetime',
        'offline_seconds' => 'integer',
        'answer_snapshot' => 'array',
    ];

    // 事件类型
    public const TYPE_HEARTBEAT = 'heartbeat';
    public const TYPE_NETWORK_LOST = 'network_lost';
    public const TYPE_PAGE_LEAVE = 'page_leave';
    public const TYPE_RESUME = 'resume';
    public const TYPE_DEVICE_SWITCH = 'device_switch';
    public const TYPE_OVERTIME_SUBMIT = 'overtime_submit';

    // 细分原因
    public const REASON_REFRESH = 'refresh';
    public const REASON_OFFLINE = 'offline';

    // 风险等级：普通事件一律不自动判作弊，仅记录供监考研判
    public const RISK_INFO = 'info';
    public const RISK_WARNING = 'warning';
    public const RISK_DANGER = 'danger';

    public const EVENT_LABELS = [
        self::TYPE_HEARTBEAT => '在线心跳',
        self::TYPE_NETWORK_LOST => '网络中断',
        self::TYPE_PAGE_LEAVE => '页面离开/刷新',
        self::TYPE_RESUME => '恢复考试',
        self::TYPE_DEVICE_SWITCH => '更换设备',
        self::TYPE_OVERTIME_SUBMIT => '超时提交',
    ];

    public const RISK_LABELS = [
        self::RISK_INFO => '正常',
        self::RISK_WARNING => '需关注',
        self::RISK_DANGER => '高风险',
    ];

    public function examRecord()
    {
        return $this->belongsTo(ExamRecord::class, 'exam_record_id');
    }
}
