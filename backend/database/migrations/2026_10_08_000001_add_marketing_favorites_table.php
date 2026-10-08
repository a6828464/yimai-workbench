<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 营销工具「收藏」独立表（与生成历史 marketing_posts 分开）。
 *
 * 为什么要独立表（而不是给 marketing_posts 加 kind/tags 列）：生成历史按 platform 静默裁剪
 * 300 条（可再生成），收藏是用户手工攒下、不可再生的劳动成果 —— 共用一张表会让
 * 「生成 300 条朋友圈」把收藏挤掉，正是本仓 O-14 那个「用户攒的东西没了」的 bug 在服务端复现。
 * 表边界保证「收藏不会串进历史」，无需靠每个查询都记得带 kind 条件来维持。
 *
 * 不建外键、不级联：与 marketing_posts（2026_09_07_000001）保持一致。代价是删用户会留孤儿行
 * （所有查询都带 user_id，不会被读到），若要级联应两张表一起改。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 幂等守卫（照抄 2026_09_07_000001 的 hasTable 写法）：兼容生产「表已建但迁移未记账」的
        // 半成功状态，重跑 migrate 不炸。本迁移是纯新增表，不改既有表/列，因此重跑无数据风险。
        if (! Schema::hasTable('marketing_favorites')) {
            Schema::create('marketing_favorites', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('platform', 10); // 朋友圈 / 小红书
                $t->string('title', 120)->default(''); // 非 nullable：读取路径不必判 null
                $t->text('content');
                // 收藏的是一次「可直接拿来发」的成品，首评（朋友圈）与封面文案（小红书）是成品的一部分；
                // 两者都可空 —— 本地旧收藏（导入路径）没有这两个字段，落 null 即可。
                $t->text('reply')->nullable();
                $t->text('cover_text')->nullable();
                $t->json('tags')->nullable(); // string[]；朋友圈为空
                $t->timestamps();
                $t->index(['user_id', 'platform', 'id'], 'mf_user_platform_idx'); // GET ?platform= 过滤 + latest('id') 排序
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_favorites');
    }
};
