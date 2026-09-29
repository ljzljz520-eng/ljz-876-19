<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * 断网续考保护：
 * - exam_records 增加 device_id / extra_time_seconds / time_check_status，状态增加 pending_review
 * - 新增 exam_connection_events（真实断网 / 刷新 / 换设备审计）
 * - 新增 exam_time_decisions（监考老师延时 / 收卷决定）
 *
 * 注意：docker-compose 的 db-init 已包含等价的幂等 SQL；本迁移供标准 Laravel 部署使用。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_connection_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('exam_record_id')->index();
            $table->string('event_type', 30)->index()->comment('heartbeat/network_lost/page_leave/resume/device_switch/overtime_submit');
            $table->string('device_id', 64)->nullable();
            $table->string('device_label')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('client_time')->nullable();
            $table->timestamp('server_time')->useCurrent();
            $table->string('reason', 50)->nullable()->comment('refresh/offline 等细分原因');
            $table->enum('risk_level', ['info', 'warning', 'danger'])->default('info');
            $table->json('answer_snapshot')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->unsignedInteger('offline_seconds')->nullable();
            $table->timestamps();

            $table->index(['risk_level']);
            $table->index(['server_time']);
        });

        Schema::create('exam_time_decisions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('exam_record_id')->index();
            $table->enum('action', ['grant_extra', 'force_submit']);
            $table->unsignedInteger('granted_seconds')->default(0);
            $table->string('comment', 500)->nullable();
            $table->unsignedBigInteger('decided_by');
            $table->timestamp('decided_at')->useCurrent();
            $table->timestamps();

            $table->index('decided_by');
        });

        Schema::table('exam_records', function (Blueprint $table) {
            $table->string('device_id', 64)->nullable()->after('status')->comment('当前考试设备ID');
            $table->unsignedInteger('extra_time_seconds')->default(0)->after('device_id')->comment('监考批准的延长秒数');
            $table->enum('time_check_status', ['normal', 'pending_review', 'approved', 'denied'])
                ->default('normal')->after('extra_time_seconds')->index();
        });

        DB::statement("ALTER TABLE exam_records MODIFY COLUMN status ENUM('in_progress', 'submitted', 'graded', 'pending_review') DEFAULT 'in_progress'");
    }

    public function down(): void
    {
        Schema::table('exam_records', function (Blueprint $table) {
            $table->dropIndex(['time_check_status']);
            $table->dropColumn(['device_id', 'extra_time_seconds', 'time_check_status']);
        });

        DB::statement("ALTER TABLE exam_records MODIFY COLUMN status ENUM('in_progress', 'submitted', 'graded') DEFAULT 'in_progress'");

        Schema::dropIfExists('exam_time_decisions');
        Schema::dropIfExists('exam_connection_events');
    }
};
