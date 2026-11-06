<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 把存量 `miniapp_config.theme = 'classic'` 刷成 `editorial`。
 *
 * ## 为什么需要这一步
 * v3.16 把主题枚举从 `classic | editorial` 收窄为 `editorial | oriental`
 * （馆主决定弃用经典主题）。读取侧 `normalizeTheme()` 已经会回落 editorial，
 * 所以**功能上没有问题**——但没有这步的话会有两个长期困扰：
 *
 *  1. 后台设置页每次加载都会弹「经典主题已停用，已切换到编辑主题」的提示条。
 *     回落发生在读取，库里的值没变，所以这个提示会一直出现。
 *  2. 数据库里存的值与界面上显示的值不一致。以后维护的人打开库看到 `classic`、
 *     打开界面看到「编辑」，第一反应是「有 bug」，然后花时间排查一个不存在的问题。
 *
 * ## 为什么 down() 不恢复
 * `classic` 已从枚举中移除。回滚这步把值写回 `classic`，得到的是一个
 * **客户端不认识的主题**——比保留 `editorial` 更糟。所以回滚是空操作，
 * 这也是有意为之：数据迁移的方向在这件事上是单向的。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_settings')) {
            return;
        }

        // 单行配置表：与 MiniappConfigController 的 `AppSetting::oldest('id')->first()` 同口径。
        // 用 DB 而不是 Eloquent 模型：这里要的是原始 JSON，绕开 casts 更不容易出错。
        $row = DB::table('app_settings')->orderBy('id')->first();
        if (! $row || ! isset($row->miniapp_config) || $row->miniapp_config === '') {
            return;
        }

        $cfg = json_decode((string) $row->miniapp_config, true);
        if (! is_array($cfg)) {
            return;
        }
        if (($cfg['theme'] ?? '') !== 'classic') {
            return;
        }

        $cfg['theme'] = 'editorial';

        DB::table('app_settings')
            ->where('id', $row->id)
            ->update([
                'miniapp_config' => json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // 见类注释：不回写 classic，避免产生客户端无法识别的主题值。
    }
};
