<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 账号支持多角色（角色叠加）。
 *
 * 语义（业务确认）：
 *  - 服务老师 + 授课老师 → 可见范围取并集（名下会籍会员 ∪ 私教课学员）
 *  - 叠加店长 → 全店（并集被更大的范围吸收）
 *
 * 保留 role 单值列作为「主角色」：
 *  - 老数据与历史查询仍可读到非空值，避免上线瞬间出现空角色；
 *  - 由 AccountController 在写入 roles 时同步维护为主角色（按权限从大到小取第一个有值的）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('roles')->nullable()->after('role');
        });

        // 回填：现有账号的角色转成单元素数组
        DB::table('users')->select('id', 'role')->orderBy('id')
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    $role = (string) ($row->role ?? '');
                    DB::table('users')->where('id', $row->id)->update([
                        'roles' => json_encode($role !== '' ? [$role] : [], JSON_UNESCAPED_UNICODE),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('roles');
        });
    }
};
