<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    protected $guarded = [];

    protected $table = 'leads';

    protected $casts = [
        'trial_cards' => 'array',
        'coupon_total' => 'integer',
        'coupon_remaining' => 'integer',
        'deal_amount' => 'float',
        'redeem_amount' => 'float',
        'deal_at' => 'datetime',
        'redeemed_at' => 'datetime',
    ];

    /**
     * 体验课卡片：**读出来时保证每张都带可用的 `session`（第几节）**。
     *
     * ## 为什么在这里做
     *
     * `session` 不是用户录入的字段，而是**派生序号**（第几节 = 数组里第几个）。
     * 但历史上有一批卡片落库时没写这个键 —— 只有 `date`/`topic`/`teacher`/`attended`
     * （见 `2026_09_16_000004_backfill_lead_trial_teacher` 那批老数据，以及早期版本
     * 卡片结构里根本没有 `session`）。缺了它之后，"谁来兜底"散落在 **5 个读方**，
     * 且口径互不一致：
     *
     * | 读方 | 原兜底 | 缺 session 时的实际结果 |
     * | ---- | ------ | ---------------------- |
     * | 列表「第 N 节」（表格 / 手机卡片） | 无 | 渲染成「第**undefined**节」 |
     * | `TodayController` 待办 key | `?? ''` | key 拼成 `trial:lead-6-`（无节次） |
     * | `TodayController` 回写匹配 | `?? ($i + 1)` | 与上一行**互相矛盾** |
     * | `api/yimai.ts` 今日待办映射 | `?? idx + 1` | 又一套 |
     * | `leads/index.vue` 排序（跟进时限基准） | `?? 0` | 全部并列，取不到「第一节」 |
     *
     * 后果**不只是显示 `undefined`**：待办 key 少了节次 → `todoAction` 里
     * `if ($session > 0)` 不成立 → **卡片回写被整段跳过**，老师在今日待办点
     * 「已接待 / 已爽约」时留资状态流转了、卡片却永远不落 `attended`/`noShow`
     * （`LeadTrialCardSessionTest` 锁定了这个行为）。
     *
     * ## 为什么是 get-only（不写 set）
     *
     * 写入仍交给 `$casts` 的 `array` 转换 —— 那是既有的、正确的一层。
     * 这里只补「读出来的形状」，且**只在缺失/非法时按数组位置补**：
     * 已有合法 `session` 的卡片原样返回，不重排、不改写用户数据。
     *
     * 补出来的是**归一化视图而非落库** —— 所以不需要数据迁移，也就不会去动
     * 线上持久化数据（回写路径顺带把补好的值带进库，属渐进自愈）。
     */
    protected function trialCards(): Attribute
    {
        return Attribute::make(
            get: function ($value): array {
                $cards = is_array($value) ? $value : (json_decode((string) $value, true) ?: []);

                $out = [];
                foreach ($cards as $i => $card) {
                    if (! is_array($card)) {
                        continue;
                    }
                    // 只在缺失/非法（< 1）时按位置补；已有合法节次的保持原值
                    if ((int) ($card['session'] ?? 0) < 1) {
                        $card['session'] = $i + 1;
                    }
                    $out[] = $card;
                }

                return $out;
            },
        );
    }
}
