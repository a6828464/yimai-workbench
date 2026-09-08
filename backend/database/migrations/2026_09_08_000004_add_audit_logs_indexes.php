<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 操作日志高频查询列加索引：模块+目标、写入时间；配合保留策略清理时按 time 删除。
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $t) {
            $t->index(['module', 'target_id'], 'audit_logs_module_target_idx');
            $t->index('time', 'audit_logs_time_idx');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $t) {
            $t->dropIndex('audit_logs_module_target_idx');
            $t->dropIndex('audit_logs_time_idx');
        });
    }
};
