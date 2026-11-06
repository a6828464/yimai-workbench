<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 小程序「到店指引」配置落库（app_settings.miniapp_config）。
 *
 * 为什么放 app_settings 而不是新建表：这是**全局单例**配置（两家店的门店信息、图文指引），
 * 与同表的 `rules`（清单阈值）/ `snapshot`（今日快照）同级 —— 一行 JSON 就够。
 * 新建表要多一套读写 + 单例保证（firstOrCreate/锁），收益为零。
 *
 * 为什么 nullable 且**不 seed**：默认值写在 `MiniappConfigController::DEFAULT_VENUES`（PHP 常量），
 * GET 在列值为 null 时回落默认值。这样：
 *  - 迁移不往库里塞业务数据（升级包里没有"某天某个默认值"的化石）；
 *  - 换默认值只改代码一处，不用写数据迁移；
 *  - 生产库保持干净：没人在后台保存过，这一行就一直是 NULL。
 *
 * 为什么默认坐标必须是原来的占位值 29.8746/121.5496（而不是 null）：小程序
 * `pkg-public/guide/guide.vue` 的 `PLACEHOLDER_COORDS` 正是用这对数字判定「没有真实坐标」，
 * 命中就降级成「复制地址」而不是把顾客导到城市中心。若后端把默认值改成 null，
 * 前端那套识别就要跟着改 —— 口径必须与小程序一致。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 幂等：生产上曾出现「列已加、后续步骤失败」的半完成状态，重跑必须安全
        if (Schema::hasTable('app_settings') && ! Schema::hasColumn('app_settings', 'miniapp_config')) {
            Schema::table('app_settings', function (Blueprint $t) {
                $t->json('miniapp_config')->nullable()->after('backup');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('app_settings') && Schema::hasColumn('app_settings', 'miniapp_config')) {
            Schema::table('app_settings', function (Blueprint $t) {
                $t->dropColumn('miniapp_config');
            });
        }
    }
};
