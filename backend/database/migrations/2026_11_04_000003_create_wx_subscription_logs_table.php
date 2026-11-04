<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 微信订阅消息下发流水（只增不改）。
 *
 * 为什么单独一张表而不是只打日志文件：订阅消息的失败原因**只能在行内复盘**——
 *  1. 43101（用户拒收）要把 remain 归零，事后必须能回答「是谁在哪一刻把次数用光的」；
 *  2. 47003（模板参数不准确）意味着 fields 映射与后台模板字段对不上，
 *     只有把实际下发的 payload 留下来才能当场比对是哪个字段超长/格式错；
 *  3. 线上按天统计 sent/skipped/failed，判断触发点（客资分配、会员归属）到底有没有生效。
 *
 * `payload` 里保存的是**下发请求体 + 微信响应**，绝不包含 access_token / appsecret
 * （两者都在查询串或服务端配置里，见 WeChatSubscribeMessage 的脱敏注释）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wx_subscription_logs')) {
            Schema::create('wx_subscription_logs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id');
                $t->string('template_key', 40);
                // sent（微信已受理）| skipped（本地就不该发：未配置/未绑定/次数为 0）| failed（微信侧报错）
                $t->string('status', 10);
                $t->integer('errcode')->nullable(); // 微信 errcode；skipped 时为 null
                // 中文原因或微信 errmsg。skipped 也写清原因，否则「为什么没收到」无从查起。
                $t->string('errmsg', 500)->default('');
                $t->json('payload')->nullable();
                $t->timestamps();

                // 按人按模板回看流水（排障主路径），以及按天统计成功率。
                $t->index(['user_id', 'template_key', 'id'], 'wx_sub_log_user_tpl_idx');
                $t->index(['status', 'id'], 'wx_sub_log_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wx_subscription_logs');
    }
};
