<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `users.email` 改为可空 —— 停用「默认邮箱」之后必须配套的一步。
 *
 * ## 背景
 *
 * 开通账号时原来会给没填邮箱的账号伪造 `{userName}@yimai.local`。这类地址收不到信、
 * 也不是员工真实联系方式，却在人员列表里和真邮箱长得一样，管理员无法区分。
 * 去掉默认邮箱后，「没有邮箱」就必须能被表达出来。
 *
 * ## 为什么不是「存空串」
 *
 * `users.email` 上有唯一索引。MySQL / SQLite 的唯一索引**允许多个 NULL**，但只允许
 * 一个 `''` —— 存空串时第二个没邮箱的账号会直接撞唯一键 500。所以只能存 NULL，
 * 列也必须放开 NOT NULL。
 *
 * 已有账号不动：老数据里的 `@yimai.local` 保持原样（它们是历史账号的既有值，
 * 批量改写会让「谁真的配了邮箱」这件事永久失真）。
 *
 * ## SQLite 注意
 *
 * SQLite 没有 ALTER COLUMN，Laravel 会重建表；重建过程可能丢掉原表上的索引。
 * 因此下面在 change() 之后检查唯一索引是否还在，不在就补回来 —— 测试库是 SQLite，
 * 少了它「两个账号同邮箱」在测试里就不报错了，回归会被静默放过。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });

        // 唯一索引兜底（口径：邮箱可以为空，但非空值必须唯一）
        $hasUniqueEmail = false;
        foreach (Schema::getIndexes('users') as $index) {
            if (($index['unique'] ?? false) && in_array('email', $index['columns'] ?? [], true)) {
                $hasUniqueEmail = true;
                break;
            }
        }
        if (! $hasUniqueEmail) {
            Schema::table('users', function (Blueprint $table) {
                $table->unique('email', 'users_email_unique');
            });
        }
    }

    public function down(): void
    {
        // 回滚前先把 NULL 邮箱补成占位值，否则 NOT NULL 改不回去（已有数据不能丢）。
        // 占位值带 userId，天然唯一，不会撞唯一索引。
        foreach (\Illuminate\Support\Facades\DB::table('users')->whereNull('email')->get(['id', 'username']) as $row) {
            \Illuminate\Support\Facades\DB::table('users')->where('id', $row->id)->update([
                'email' => 'noreply+'.$row->id.'@yimai.local',
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
