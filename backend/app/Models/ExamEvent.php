<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'exam_record_id',
        'user_id',
        'event_type',
        'client_ts',
        'server_ts',
        'device_id',
        'is_online',
        'duration',
        'meta',
    ];

    protected $casts = [
        'exam_record_id' => 'integer',
        'user_id' => 'integer',
        'client_ts' => 'integer',
        'server_ts' => 'datetime',
        'is_online' => 'boolean',
        'duration' => 'integer',
        'meta' => 'array',
    ];

    // 常规心跳，用于判断在线状态
    public const TYPE_HEARTBEAT = 'heartbeat';
    // 浏览器监测到断网(window offline / 请求连续失败)
    public const TYPE_OFFLINE_DETECTED = 'offline_detected';
    // 网络恢复并重新连上服务端
    public const TYPE_RECONNECT = 'reconnect';
    // 页面被切走/最小化(visibilitychange hidden)
    public const TYPE_PAGE_HIDDEN = 'page_hidden';
    // 页面重新可见
    public const TYPE_PAGE_VISIBLE = 'page_visible';
    // 刷新/重新打开页面后恢复会话
    public const TYPE_PAGE_REFRESH = 'page_refresh';
    // 断线期间缓存的会话恢复(网络恢复后立即同步)
    public const TYPE_SESSION_RESUME = 'session_resume';
    // 设备指纹变化(疑似换设备/换浏览器)
    public const TYPE_DEVICE_SWITCH = 'device_switch';
    // 批量答案同步
    public const TYPE_ANSWER_SYNC = 'answer_sync';
    // 正常交卷
    public const TYPE_SUBMIT = 'submit';
    // 到时自动交卷
    public const TYPE_AUTO_SUBMIT = 'auto_submit';
    // 超过截止时间+宽限期仍未交卷
    public const TYPE_OVERDUE = 'overdue';
    // 监考批准延时
    public const TYPE_REVIEW_EXTEND = 'review_extend';
    // 监考终止考试(按现有答卷判分)
    public const TYPE_REVIEW_TERMINATE = 'review_terminate';

    public const TYPES = [
        self::TYPE_HEARTBEAT => '心跳',
        self::TYPE_OFFLINE_DETECTED => '断网',
        self::TYPE_RECONNECT => '网络恢复',
        self::TYPE_PAGE_HIDDEN => '页面切出',
        self::TYPE_PAGE_VISIBLE => '页面返回',
        self::TYPE_PAGE_REFRESH => '刷新页面',
        self::TYPE_SESSION_RESUME => '离线恢复',
        self::TYPE_DEVICE_SWITCH => '设备变更',
        self::TYPE_ANSWER_SYNC => '答案同步',
        self::TYPE_SUBMIT => '交卷',
        self::TYPE_AUTO_SUBMIT => '自动交卷',
        self::TYPE_OVERDUE => '超时未交',
        self::TYPE_REVIEW_EXTEND => '批准延时',
        self::TYPE_REVIEW_TERMINATE => '终止考试',
    ];

    public function examRecord()
    {
        return $this->belongsTo(ExamRecord::class, 'exam_record_id');
    }
}
