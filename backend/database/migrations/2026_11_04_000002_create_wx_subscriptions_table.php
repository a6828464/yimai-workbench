<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 微信订阅消息的「授权次数账本」。
 *
 * 为什么必须有这张表：订阅消息是**一次性**授权 —— 用户在 `wx.requestSubscribeMessage`
 * 里点一次「允许」，只换到**一次**下发机会，发完即失效，微信侧不提供任何查询接口。
 * 也就是说「这个人还能收几条」这个事实只存在于我们自己的服务端；不记账就只能
 * 盲目下发，然后在 43101（用户拒收）里堆满日志、并且永远修不好。
 *
 * 口径：
 *  - `remain`        剩余可下发条数（授权一次 +1，发送成功 -1，43101 归零）。
 *  - `total_accepted` 历史累计授权次数，用于区分「从没授权」与「授权过但已用完」——
 *                    前者要引导用户去授权，后者要引导用户再点一次「开启提醒」。
 *  - 唯一键 (user_id, template_key)：一个账号一个模板只有一行，靠 upsert 累加，
 *                    并发下不会插出两行导致 remain 分裂。
 *
 * 不建外键（与 marketing_favorites 同口径）：删账号会留孤儿行，所有读取都带
 * user_id，孤儿行不会被任何人读到；要级联应两张微信表一起改。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 幂等守卫（照抄 2026_09_07_000001 / 2026_10_08_000001 的 hasTable 写法）：
        // 兼容生产「表已建但迁移未记账」的半成功状态，重跑 migrate 不炸。
        if (! Schema::hasTable('wx_subscriptions')) {
            Schema::create('wx_subscriptions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id');
                // 模板键是 config('services.wechat.templates') 里的键（leadAssigned / memberAssigned /
                // todoReminder），**不是**微信侧的 template_id —— 后者会随重新申请模板而变，
                // 存键才能让「换模板 id」不丢历史授权。
                $t->string('template_key', 40);
                $t->integer('remain')->default(0); // 剩余可下发条数，不允许为负
                $t->integer('total_accepted')->default(0); // 历史累计授权次数
                $t->timestamp('last_accepted_at')->nullable();
                $t->timestamp('last_sent_at')->nullable();
                $t->timestamps();

                $t->unique(['user_id', 'template_key'], 'wx_sub_user_tpl_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wx_subscriptions');
    }
};
