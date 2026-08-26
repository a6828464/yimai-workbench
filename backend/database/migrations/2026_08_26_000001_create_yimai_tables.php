<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 用户扩展：用户名登录 + 角色 + 门店范围（name 复用默认列）
        Schema::table('users', function (Blueprint $t) {
            $t->string('username')->unique()->after('id');
            $t->string('role', 20)->default('R_TEACHER')->after('name');
            $t->string('venue')->nullable()->after('role');
            $t->json('venues')->nullable()->after('venue');
        });

        // 留资（前端客资）
        Schema::create('leads', function (Blueprint $t) {
            $t->id();
            $t->date('lead_date');
            $t->string('name');
            $t->string('phone', 20)->default('');
            $t->string('demand')->default('');
            $t->string('source')->default('');
            $t->string('venue', 10);
            $t->string('service_teacher')->default('');
            $t->string('status', 20)->default('新留资');
            $t->string('grade', 4)->default('');
            $t->string('trial_time')->default('');
            $t->string('trial_topic')->default('');
            $t->string('trial_teacher')->default('');
            $t->string('deal_card')->default('');
            $t->unsignedBigInteger('deal_amount')->nullable();
            $t->unsignedBigInteger('redeem_amount')->nullable();
            $t->string('voucher_code')->default('');
            $t->text('remark')->default('');
            $t->string('created_by')->default('');
            $t->timestamps();
        });

        // 客户/会员主档
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('phone', 20)->default('');
            $t->string('phone_tail', 8)->default('');
            $t->string('venue', 10);
            $t->string('source')->default('');
            $t->string('main_card')->default('—');
            $t->integer('remain_times')->nullable();
            $t->date('expire_date')->nullable();
            $t->date('last_visit')->nullable();
            $t->string('layer', 4)->default('P5');
            $t->string('status')->default('跟进中');
            $t->string('owner')->default('未分配');
            $t->string('next_action')->default('');
            $t->string('next_action_time')->default('');
            $t->string('external_id')->nullable()->unique();
            $t->unsignedSmallInteger('attend_m1')->default(0);
            $t->unsignedSmallInteger('attend_m2')->default(0);
            $t->unsignedSmallInteger('attend_m3')->default(0);
            $t->integer('total_purchased')->default(0);
            $t->json('renewal_plan')->nullable();
            $t->json('decline')->nullable();
            $t->string('stop_reason')->default('');
            $t->string('expected_return')->default('');
            $t->date('last_touch')->nullable();
            $t->boolean('needs_help')->default(false);
            $t->boolean('in_revive')->default(false);
            $t->integer('eval_score')->nullable();
            $t->date('eval_at')->nullable();
            $t->timestamps();
        });

        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->string('customer_name');
            $t->string('venue', 10);
            $t->string('owner')->default('未分配');
            $t->string('priority', 6)->default('中');
            $t->string('deadline')->default('');
            $t->string('status', 12)->default('待接收');
            $t->string('standard')->default('');
            $t->timestamps();
        });

        Schema::create('approvals', function (Blueprint $t) {
            $t->id();
            $t->string('customer_name');
            $t->string('applicant');
            $t->string('card_name');
            $t->unsignedBigInteger('standard_price');
            $t->unsignedBigInteger('request_price');
            $t->string('reason')->default('');
            $t->string('status', 20)->default('待店长初审');
            $t->string('apply_time')->default('');
            $t->timestamps();
        });

        Schema::create('training_plans', function (Blueprint $t) {
            $t->id();
            $t->string('member_name');
            $t->json('profile')->nullable();      // 年龄/性别/身高体重体脂/关注点
            $t->json('goal')->nullable();         // 核心目标/频率/周期/阶段目标/风险
            $t->string('status', 20)->default('待生成');
            $t->json('content')->nullable();      // summary/phases/cautions
            $t->json('images')->nullable();       // [{url,label}]
            $t->json('share')->nullable();        // {enabled,code,views}
            $t->string('source', 10)->default('');
            $t->string('created_by')->default('');
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->timestamp('time')->useCurrent();
            $t->string('operator_name');
            $t->string('operator_role', 12);
            $t->string('action', 16);
            $t->string('module', 20);
            $t->string('target_id', 24)->default('0');
            $t->string('target_label')->default('');
            $t->string('venue', 10)->default('双店');
            $t->text('detail')->default('');
        });

        // 单行设置：清单规则 + 同步快照
        Schema::create('app_settings', function (Blueprint $t) {
            $t->id();
            $t->json('rules')->nullable();
            $t->json('snapshot')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('training_plans');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('leads');
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['username', 'role', 'venue', 'venues']);
        });
    }
};
