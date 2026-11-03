<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 微信授权登录所需的身份绑定列。
 *
 * 口径说明（为什么是两列 + 一个唯一索引）：
 *  - openid 是「同一用户在同一小程序」的稳定标识，是本项目唯一用于登录比对的键。
 *  - unionid 只有小程序与公众号等在同一个开放平台账号下绑定时才会下发，
 *    不能作为登录依据，只作未来打通公众号会员身份的预留位。
 *  - openid 上建唯一索引：小程序登录是并发高频入口，靠应用层「先查再写」
 *    在并发下会插出两条绑定记录，届时同一个微信会随请求落到不同账号。
 *
 * 注意：openid 允许为 NULL —— 现有 100% 的账号都是账号密码注册的，
 * 加唯一索引不会与 NULL 冲突（MySQL/SQLite 的唯一索引允许多个 NULL）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('wx_openid', 64)->nullable()->after('password');
            $table->string('wx_unionid', 64)->nullable()->after('wx_openid');
            $table->timestamp('wx_bound_at')->nullable()->after('wx_unionid');

            $table->unique('wx_openid', 'users_wx_openid_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_wx_openid_unique');
            $table->dropColumn(['wx_openid', 'wx_unionid', 'wx_bound_at']);
        });
    }
};
