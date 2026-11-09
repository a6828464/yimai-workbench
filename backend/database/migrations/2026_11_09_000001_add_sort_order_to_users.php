<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 人员列表的「手动顺序」列。
 *
 * 为什么需要一列而不是复用 id：id 是开通先后，管理员想表达的是**业务顺序**
 * （店长在首位、老师按资历、离职的放末尾），这两个顺序天然会分叉，改 id 不现实。
 *
 * 默认 0 而不是 NULL：现有账号全部落在同一个 0 上，`orderBy('sort_order')->orderBy('id')`
 * 就会退化成原来的「开通先后」—— 迁移不需要回填任何数据，升级前后顺序完全一致。
 * 一旦有人在「调整顺序」里拖过一次，后端会把这批账号重写成 10/20/30…，
 * 之后新开通的账号取 max+10 接在末尾。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->integer('sort_order')->default(0)->after('venues');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
