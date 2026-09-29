<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 断网续考保护
 *
 * - exam_records: 增加截止时间/心跳/离线累计/设备指纹/宽限期/监考审批字段，扩展 status
 * - exam_record_answers: 增加客户端时间戳/同步时间，增加(记录,题目)唯一键以支持 upsert
 * - exam_events: 新增考试过程事件表(心跳、断网、刷新、换设备、审批)
 *
 * 本迁移幂等，可在由 docker-compose 内联 SQL 初始化或旧版库上重复执行。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. exam_records 扩列 + 扩枚举
        $recordColumns = DB::select("SHOW COLUMNS FROM exam_records");
        $existing = array_column($recordColumns, 'Field');

        $adds = [
            'deadline_at'       => "ADD COLUMN deadline_at TIMESTAMP NULL COMMENT '当前应截止时间(含延时)' AFTER end_time",
            'last_heartbeat_at' => "ADD COLUMN last_heartbeat_at TIMESTAMP NULL COMMENT '最近一次在线心跳时间' AFTER deadline_at",
            'offline_total'     => "ADD COLUMN offline_total INT DEFAULT 0 COMMENT '累计离线时长(秒)' AFTER last_heartbeat_at",
            'device_id'         => "ADD COLUMN device_id VARCHAR(64) NULL COMMENT '首个考试会话设备指纹' AFTER offline_total",
            'grace_until'       => "ADD COLUMN grace_until TIMESTAMP NULL COMMENT '断网宽限期截止时间' AFTER device_id",
            'review_reason'     => "ADD COLUMN review_reason VARCHAR(100) NULL COMMENT '待处理原因' AFTER grace_until",
            'reviewed_by'       => "ADD COLUMN reviewed_by BIGINT UNSIGNED NULL COMMENT '处理老师ID' AFTER review_reason",
            'reviewed_at'       => "ADD COLUMN reviewed_at TIMESTAMP NULL COMMENT '处理时间' AFTER reviewed_by",
            'review_remark'     => "ADD COLUMN review_remark VARCHAR(255) NULL COMMENT '处理备注' AFTER reviewed_at",
        ];

        foreach ($adds as $field => $clause) {
            if (!in_array($field, $existing, true)) {
                DB::statement("ALTER TABLE exam_records $clause");
            }
        }

        // 扩展 status 枚举（MySQL 会保留列属性）
        DB::statement("ALTER TABLE exam_records MODIFY status ENUM('in_progress','submitted','graded','awaiting_review','terminated') DEFAULT 'in_progress' COMMENT '状态'");

        if (!$this->indexExists('exam_records', 'idx_review')) {
            DB::statement("ALTER TABLE exam_records ADD INDEX idx_review (status, reviewed_by)");
        }

        // 2. exam_record_answers 扩列 + 唯一键
        $answerColumns = DB::select("SHOW COLUMNS FROM exam_record_answers");
        $answerExisting = array_column($answerColumns, 'Field');

        if (!in_array('client_updated_at', $answerExisting, true)) {
            DB::statement("ALTER TABLE exam_record_answers ADD COLUMN client_updated_at BIGINT NULL COMMENT '考生端答案修改时间戳(毫秒)' AFTER score");
        }
        if (!in_array('synced_at', $answerExisting, true)) {
            DB::statement("ALTER TABLE exam_record_answers ADD COLUMN synced_at TIMESTAMP NULL COMMENT '最近服务端同步时间' AFTER client_updated_at");
        }
        if (!in_array('meta', $answerExisting, true)) {
            DB::statement("ALTER TABLE exam_record_answers ADD COLUMN meta JSON NULL COMMENT '附加信息(题目状态等)' AFTER synced_at");
        }
        // 允许空答案(只保存题目状态、断网期间空白同步)
        DB::statement("ALTER TABLE exam_record_answers MODIFY answer TEXT NULL COMMENT '考生答案(最新版本)'");
        if (!$this->indexExists('exam_record_answers', 'uk_record_question')) {
            // 历史脏数据下去重后再加唯一键；正常考试(记录,题目)天然唯一
            DB::statement("DELETE a1 FROM exam_record_answers a1
                INNER JOIN exam_record_answers a2
                ON a1.exam_record_id = a2.exam_record_id
                   AND a1.question_id = a2.question_id
                   AND a1.id > a2.id");
            DB::statement("ALTER TABLE exam_record_answers ADD UNIQUE KEY uk_record_question (exam_record_id, question_id)");
        }

        // 3. exam_events
        DB::statement("CREATE TABLE IF NOT EXISTS exam_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            exam_record_id BIGINT UNSIGNED NOT NULL COMMENT '考试记录ID',
            user_id BIGINT UNSIGNED NOT NULL COMMENT '考生ID',
            event_type VARCHAR(40) NOT NULL COMMENT '事件类型',
            client_ts BIGINT NULL COMMENT '考生端本地时间戳(毫秒)',
            server_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '服务端接收时间',
            device_id VARCHAR(64) NULL COMMENT '上报设备指纹',
            is_online TINYINT(1) DEFAULT 1 COMMENT '上报时是否在线: 0为离线补报',
            duration INT NULL COMMENT '与上一状态持续时长(秒)',
            meta JSON NULL COMMENT '附加信息',
            INDEX idx_exam_record_id (exam_record_id),
            INDEX idx_user_id (user_id),
            INDEX idx_event_type (event_type),
            INDEX idx_server_ts (server_ts)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='考试过程事件表'");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS exam_events");

        if ($this->indexExists('exam_record_answers', 'uk_record_question')) {
            DB::statement("ALTER TABLE exam_record_answers DROP INDEX uk_record_question");
        }
        foreach (['synced_at', 'meta', 'client_updated_at'] as $col) {
            if (in_array($col, array_column(DB::select("SHOW COLUMNS FROM exam_record_answers"), 'Field'), true)) {
                DB::statement("ALTER TABLE exam_record_answers DROP COLUMN $col");
            }
        }

        DB::statement("ALTER TABLE exam_records MODIFY status ENUM('in_progress','submitted','graded') DEFAULT 'in_progress' COMMENT '状态'");
        if ($this->indexExists('exam_records', 'idx_review')) {
            DB::statement("ALTER TABLE exam_records DROP INDEX idx_review");
        }
        foreach (['review_remark', 'reviewed_at', 'reviewed_by', 'review_reason', 'grace_until', 'device_id', 'offline_total', 'last_heartbeat_at', 'deadline_at'] as $col) {
            if (in_array($col, array_column(DB::select("SHOW COLUMNS FROM exam_records"), 'Field'), true)) {
                DB::statement("ALTER TABLE exam_records DROP COLUMN $col");
            }
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
