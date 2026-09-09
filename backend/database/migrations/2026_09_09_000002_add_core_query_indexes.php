<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 核心业务表高频查询列补索引（审查发现 customers/leads/tasks 建表时几乎零索引）：
     * - customers：门店隔离（venue）、手机号匹配（phone）、五清单引擎与看板（layer/last_visit/expire_date）
     * - leads：查重（phone）、列表筛选（venue/status/lead_date）、老师归属（service_teacher）
     * - tasks：待办按负责人/门店/截止日过滤
     * - training_plans：按创建人整表读写
     * - sync_jobs：僵尸任务回收（venue+status+started_at）
     * - ky_cards：新客培养按 member_id 预载预约事实
     * 幂等：hasIndex 防生产半完成状态重跑（沿用 2026_09_06_000003 的自愈惯例）。
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $this->indexOnce($t, ['venue'], 'customers_venue_idx');
            $this->indexOnce($t, ['phone'], 'customers_phone_idx');
            $this->indexOnce($t, ['layer'], 'customers_layer_idx');
            $this->indexOnce($t, ['last_visit'], 'customers_last_visit_idx');
            $this->indexOnce($t, ['expire_date'], 'customers_expire_date_idx');
        });

        Schema::table('leads', function (Blueprint $t) {
            $this->indexOnce($t, ['phone'], 'leads_phone_idx');
            $this->indexOnce($t, ['venue'], 'leads_venue_idx');
            $this->indexOnce($t, ['status'], 'leads_status_idx');
            $this->indexOnce($t, ['lead_date'], 'leads_lead_date_idx');
            $this->indexOnce($t, ['service_teacher'], 'leads_service_teacher_idx');
        });

        Schema::table('tasks', function (Blueprint $t) {
            $this->indexOnce($t, ['owner', 'status'], 'tasks_owner_status_idx');
            $this->indexOnce($t, ['venue', 'status'], 'tasks_venue_status_idx');
            $this->indexOnce($t, ['deadline'], 'tasks_deadline_idx');
        });

        Schema::table('training_plans', function (Blueprint $t) {
            $this->indexOnce($t, ['created_by'], 'training_plans_created_by_idx');
        });

        Schema::table('sync_jobs', function (Blueprint $t) {
            $this->indexOnce($t, ['venue', 'status', 'started_at'], 'sync_jobs_venue_status_started_idx');
        });

        Schema::table('ky_cards', function (Blueprint $t) {
            $this->indexOnce($t, ['member_id'], 'ky_cards_member_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $this->dropIndexOnce($t, 'customers_venue_idx');
            $this->dropIndexOnce($t, 'customers_phone_idx');
            $this->dropIndexOnce($t, 'customers_layer_idx');
            $this->dropIndexOnce($t, 'customers_last_visit_idx');
            $this->dropIndexOnce($t, 'customers_expire_date_idx');
        });

        Schema::table('leads', function (Blueprint $t) {
            $this->dropIndexOnce($t, 'leads_phone_idx');
            $this->dropIndexOnce($t, 'leads_venue_idx');
            $this->dropIndexOnce($t, 'leads_status_idx');
            $this->dropIndexOnce($t, 'leads_lead_date_idx');
            $this->dropIndexOnce($t, 'leads_service_teacher_idx');
        });

        Schema::table('tasks', function (Blueprint $t) {
            $this->dropIndexOnce($t, 'tasks_owner_status_idx');
            $this->dropIndexOnce($t, 'tasks_venue_status_idx');
            $this->dropIndexOnce($t, 'tasks_deadline_idx');
        });

        Schema::table('training_plans', function (Blueprint $t) {
            $this->dropIndexOnce($t, 'training_plans_created_by_idx');
        });

        Schema::table('sync_jobs', function (Blueprint $t) {
            $this->dropIndexOnce($t, 'sync_jobs_venue_status_started_idx');
        });

        Schema::table('ky_cards', function (Blueprint $t) {
            $this->dropIndexOnce($t, 'ky_cards_member_id_idx');
        });
    }

    private function indexOnce(Blueprint $t, array $columns, string $name): void
    {
        $existing = collect(Schema::getIndexes($t->getTable()))->pluck('name')->contains($name);
        if (! $existing) {
            $t->index($columns, $name);
        }
    }

    private function dropIndexOnce(Blueprint $t, string $name): void
    {
        $existing = collect(Schema::getIndexes($t->getTable()))->pluck('name')->contains($name);
        if ($existing) {
            $t->dropIndex($name);
        }
    }
};
