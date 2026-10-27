<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Support\CardLifecycle;
use Illuminate\Console\Command;

/**
 * 「待续费」全量审计（只读）。
 *
 * 发布前后用它回答三类问题：
 *  1. 口径有没有生效：`timeCardMissed` / `storedValueMisjudged` / `historyOnlyHits`
 *     必须为 **0**（F1、D05、I1 的守门指标）；
 *  2. 人群迁移是否在预期量级：`--diff-legacy` 给出 movedOut / movedIn（规范 §9.3：
 *     快照口径 movedOut 35–45、movedIn 10–20，偏差 >50% 说明实现口径不一致）；
 *  3. 是否需要重新同步：`dataVersionStale > 0`。
 *
 * 只读承诺：不写库、不重算分层、不失效缓存；逐会员调用同一个 `customerDecision()`。
 *
 * 用法：
 *   php artisan members:audit-renewal
 *   php artisan members:audit-renewal --venue=绿地店 --diff-legacy
 *   php artisan members:audit-renewal --format=csv --limit=50
 */
class MembersAuditRenewal extends Command
{
    protected $signature = 'members:audit-renewal
        {--venue= : 只审计某一门店（留空=全部）}
        {--limit=0 : 最多处理多少个会员（0=不限）}
        {--format=json : 输出格式 json|csv}
        {--diff-legacy : 与 v3.8 口径逐人对账（movedOut/movedIn）}
        {--window= : 覆盖「过期有余额」回溯窗（天，仅本次审计）}
        {--json : --format=json 的别名开关}';

    protected $description = '只读审计「待续费」判定：各触发类型、降级码、仅历史卡误报、旧口径对账';

    public function handle(): int
    {
        $rules = rules();
        if (is_numeric($this->option('window'))) {
            $rules['expiredBalanceWindowDays'] = (int) $this->option('window');
        }

        $limit = max(0, (int) $this->option('limit'));
        $venue = trim((string) $this->option('venue'));
        $diffLegacy = (bool) $this->option('diff-legacy');
        $format = (string) $this->option('format');
        if ($this->option('json')) {
            $format = 'json';
        }
        if (! in_array($format, ['json', 'csv'], true)) {
            $this->error('--format 只支持 json|csv');

            return self::FAILURE;
        }

        $stats = [
            'total' => 0,
            'renewal' => ['in' => 0, 'urgent' => 0, 'observe' => 0, 'pendingStart' => 0],
            'byCode' => [],
            'byCodeByType' => ['deadline_near' => ['type1' => 0, 'type2' => 0, 'type3' => 0]],
            'degraded' => [],
            'historyOnlyHits' => 0,
            'timeCardMissed' => 0,
            'storedValueMisjudged' => 0,
            'layerDistribution' => [],
            'expiredBalance' => ['in' => 0, 'sections' => 0, 'amount' => 0.0],
            'dataVersionStale' => 0,
            'bombIn' => 0,
        ];
        $rows = [];
        $movedOut = $movedIn = [];
        // A-04：原 `layerChanged` 与 movedOut/movedIn 是**同一个条件**（in 是否变化），
        // 名字却暗示「P 层变化」，上线对账时会被读成「分层变了 N 人」。
        // 现改名为 `inChanged`（入清单状态变化），不再输出同条件的 layerChanged 数组。
        $inChanged = [];

        $query = scopeMemberCustomers(Customer::query());
        if ($venue !== '') {
            $query->where('venue', $venue);
        }

        $query->select([
            'id', 'name', 'venue', 'external_id', 'main_card', 'remain_times', 'expire_date',
            'last_visit', 'attend_m1', 'attend_m2', 'attend_m3', 'in_revive', 'card_paid_amount',
            'card_stats', 'cards_list', 'layer',
        ])->orderBy('id')->chunkById(500, function ($customers) use (
            &$stats, &$rows, &$movedOut, &$movedIn, &$inChanged, $rules, $diffLegacy, $limit, $format
        ) {
            foreach ($customers as $customer) {
                if ($limit > 0 && $stats['total'] >= $limit) {
                    return false;
                }

                $decision = customerDecision($customer, $rules);
                $renewal = $decision['renewal'];
                $stats['total']++;
                $stats['layerDistribution'][$decision['layer']] = ($stats['layerDistribution'][$decision['layer']] ?? 0) + 1;

                if ($renewal['in']) {
                    $stats['renewal']['in']++;
                    if ($renewal['urgent']) {
                        $stats['renewal']['urgent']++;
                    } else {
                        $stats['renewal']['observe']++;
                    }
                }
                if ($renewal['bucket'] === '待开卡') {
                    $stats['renewal']['pendingStart']++;
                }
                foreach ($renewal['whyCodes'] as $code) {
                    $stats['byCode'][$code] = ($stats['byCode'][$code] ?? 0) + 1;
                }
                foreach ($renewal['hits'] as $hit) {
                    if ($hit['code'] === 'deadline_near') {
                        $type = (int) ($hit['type'] ?? 0);
                        $key = 'type'.$type;
                        $stats['byCodeByType']['deadline_near'][$key] = ($stats['byCodeByType']['deadline_near'][$key] ?? 0) + 1;
                    }
                }
                foreach ($renewal['degradedCodes'] as $code) {
                    $stats['degraded'][$code] = ($stats['degraded'][$code] ?? 0) + 1;
                }
                if ($decision['dataVersion'] < CardLifecycle::DATA_VERSION) {
                    $stats['dataVersionStale']++;
                }
                if ($decision['expiredBalance']['in']) {
                    $stats['expiredBalance']['in']++;
                    $stats['expiredBalance']['sections'] += $decision['expiredBalance']['sections'];
                    $stats['expiredBalance']['amount'] += $decision['expiredBalance']['amount'];
                }
                if ($decision['bomb']['in']) {
                    $stats['bombIn']++;
                }

                // 守门指标 1：命中完全由历史实例造成（新口径必须为 0）。
                // 结构性保证在 hits 只由 CURRENT 构造；这里用「去掉 historyCards 后 in 是否翻转」复核。
                if ($renewal['in'] && $this->inWithoutHistory($customer, $rules)) {
                    $stats['historyOnlyHits']++;
                }

                // 守门指标 2：有当前期限卡落在到期窗内、却没有 deadline_near 命中（F1 漏判）。
                if ($this->timeCardMissed($renewal, $rules)) {
                    $stats['timeCardMissed']++;
                }

                // 守门指标 3：储值卡被当作节/天参与阈值（D05）。
                if ($this->storedValueMisjudged($renewal)) {
                    $stats['storedValueMisjudged']++;
                }

                if ($diffLegacy) {
                    $legacy = $this->legacyRenewal($customer, $rules, $renewal['instances']);
                    if ($legacy['in'] && ! $renewal['in']) {
                        $movedOut[] = [
                            'customerId' => $customer->id,
                            'name' => $customer->name,
                            'layer' => $decision['layer'],
                            // A-04：真实 legacy 命中码（旧实现是常量 'legacy-in'，伪装成理由）
                            'why' => $legacy['codes'],
                            'legacyIn' => true,
                            'in' => false,
                        ];
                    } elseif (! $legacy['in'] && $renewal['in']) {
                        $movedIn[] = [
                            'customerId' => $customer->id,
                            'name' => $customer->name,
                            'layer' => $decision['layer'],
                            'why' => $renewal['whyCodes'],
                            'legacyIn' => false,
                            'in' => true,
                        ];
                    }
                    if ($legacy['in'] !== $renewal['in']) {
                        // 语义：入清单状态变化（不是 P 层变化）。同一人只会出现一次。
                        $inChanged[] = [
                            'customerId' => $customer->id,
                            'name' => $customer->name,
                            'layer' => $decision['layer'],
                            'legacyIn' => $legacy['in'],
                            'in' => $renewal['in'],
                        ];
                    }
                }

                if ($format === 'csv') {
                    $rows[] = [
                        $customer->id,
                        $customer->name,
                        $customer->external_id,
                        $renewal['in'] ? '1' : '0',
                        $renewal['bucket'],
                        $renewal['urgent'] ? '1' : '0',
                        $decision['layer'],
                        implode('|', $renewal['whyCodes']),
                        implode('|', $renewal['degradedCodes']),
                        $decision['display']['mainCard'],
                        $decision['display']['mainCardState'],
                        $decision['display']['expireDate'],
                        $decision['display']['expireDateState'],
                        $decision['display']['usableCount'],
                        $decision['display']['displayCount'],
                        $decision['expiredWithBalance'] ? '1' : '0',
                        $decision['bomb']['in'] ? '1' : '0',
                    ];
                } elseif ($format === 'json' && $limit > 0) {
                    // 带 limit 的 JSON 偏「抽样复核」：逐人输出核心结论
                    $rows[] = [
                        'customerId' => $customer->id,
                        'name' => $customer->name,
                        'source' => $customer->external_id,
                        'in' => $renewal['in'],
                        'bucket' => $renewal['bucket'],
                        'urgent' => $renewal['urgent'],
                        'layer' => $decision['layer'],
                        'whyCodes' => $renewal['whyCodes'],
                        'degradedCodes' => $renewal['degradedCodes'],
                        'mainCard' => $decision['display']['mainCard'],
                        'mainCardState' => $decision['display']['mainCardState'],
                        'expireDate' => $decision['display']['expireDate'],
                        'expireDateState' => $decision['display']['expireDateState'],
                        'expiredWithBalance' => $decision['expiredWithBalance'],
                        'bomb' => $decision['bomb']['in'],
                    ];
                }
            }

            return true;
        });

        ksort($stats['byCode']);
        ksort($stats['degraded']);
        ksort($stats['layerDistribution']);

        $payload = $stats;
        if ($diffLegacy) {
            $payload['diffLegacy'] = [
                'movedOut' => $movedOut,
                'movedIn' => $movedIn,
                // A-04：入清单状态变化（movedOut ∪ movedIn），**不是** P 层变化。
                // 原 `layerChanged` 与本数组同条件且语义误导，已移除；消费方请改读 inChanged。
                'inChanged' => $inChanged,
                'movedOutCount' => count($movedOut),
                'movedInCount' => count($movedIn),
                'inChangedCount' => count($inChanged),
                'schema' => [
                    'inChanged' => '入清单状态变化（legacyIn !== in）；P 层不在本对账范围内',
                    'layerChanged' => 'deprecated/removed：与 inChanged 同条件，v3.9-t8 起不再输出',
                ],
            ];
        }
        if ($format === 'csv') {
            $payload['rows'] = $rows;
        } elseif ($limit > 0) {
            $payload['rows'] = $rows;
        }

        if ($format === 'csv') {
            $this->line('customer_id,name,source,in,bucket,urgent,layer,whyCodes,degraded,mainCard,mainCardState,expireDate,expireDateState,usableCount,displayCount,expiredWithBalance,bomb');
            foreach ($rows as $row) {
                $this->line(implode(',', array_map(fn ($v) => str_contains((string) $v, ',') ? '"'.str_replace('"', '""', (string) $v).'"' : (string) $v, $row)));
            }
            $this->line('');
            $this->line('# summary '.json_encode($stats, JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    /** 去掉 historyCards 后 in 是否翻转（true = 该人的命中完全由历史实例造成） */
    private function inWithoutHistory(Customer $customer, array $rules): bool
    {
        if (! array_key_exists('historyCards', (array) ($customer->card_stats ?? []))) {
            return false;
        }
        $clone = clone $customer;
        $stats = (array) $clone->card_stats;
        unset($stats['historyCards']);
        $clone->card_stats = $stats;

        return ! customerDecision($clone, $rules)['renewal']['in'];
    }

    private function timeCardMissed(array $renewal, array $rules): bool
    {
        $expireDays = (int) ($rules['renewalExpireDays'] ?? 30);
        $hasNearHit = false;
        foreach ($renewal['hits'] as $hit) {
            if ($hit['code'] === 'deadline_near') {
                $hasNearHit = true;
            }
        }
        if ($hasNearHit) {
            return false;
        }
        foreach ($renewal['instances'] as $instance) {
            if ($instance['type'] === CardLifecycle::TYPE_TIME
                && $instance['state'] === 'current'
                && $instance['daysUntil'] !== null
                && $instance['daysUntil'] >= 0
                && $instance['daysUntil'] <= $expireDays) {
                return true;
            }
        }

        return false;
    }

    /** 储值卡被当成节/天（unit 非「元」）或参与次卡阈值即视为误判 */
    private function storedValueMisjudged(array $renewal): bool
    {
        foreach ($renewal['instances'] as $instance) {
            if ($instance['type'] !== CardLifecycle::TYPE_STORED) {
                continue;
            }
            if (($instance['unit'] ?? '') !== '元') {
                return true;
            }
        }
        foreach ($renewal['hits'] as $hit) {
            if (in_array($hit['code'], ['count_card_low', 'count_card_percent_low', 'count_total_low', 'count_total_percent_low'], true)
                && (int) ($hit['type'] ?? 0) === CardLifecycle::TYPE_STORED) {
                return true;
            }
        }

        return false;
    }

    /**
     * v3.8（HEAD）口径的重建，用于 `--diff-legacy` 对账。
     *
     * 重建的是 HEAD 的**输入集合**：`status ∈ {4,5,7}` 且非体验卡（HEAD 把
     * deadline<TODAY 的 4/5 实例也当作在用），再套 HEAD 的判定链（C8 过期回溯、
     * 汇总/逐卡并存、待开卡计数断言）。
     *
     * ⚠️ 两个已知近似（因此对账只用于量级校验，不作逐人结论）：
     *  ① HEAD 的期限卡 `residue_amount` 恒 0（F1），重建时把 type=2 的残余强制为 0，
     *     否则会用新 cards_list 的「剩余天数」错误复活 HEAD 的 liveTime 分支；
     *  ② HEAD 的 `main_card` 由旧排序键产生，这里只能用当前 `main_card` 近似。
     */
    /**
     * v3.8（HEAD）口径重建，返回 `['in' => bool, 'codes' => string[]]`。
     *
     * `codes` 是重建出来的 legacy 命中码（total/total_pct/card/exhausted/deadline_near/
     * deadline_expired/...），用于 `--diff-legacy` 的 movedOut[].why —— 旧实现输出常量
     * `'legacy-in'`，看起来像理由但没有任何信息（A-04）。
     */
    private function legacyRenewal(Customer $customer, array $rules, array $instances): array
    {
        $threshold = (int) ($rules['renewalThreshold'] ?? 10);
        $countPercent = (int) ($rules['renewalCountPercent'] ?? 0);
        $expireDays = (int) ($rules['renewalExpireDays'] ?? 30);
        $backfill = (int) ($rules['renewalExpiredBackfillDays'] ?? 90);

        $active = [];
        foreach ($instances as $instance) {
            $code = (string) ($instance['statusCode'] ?? '');
            if (! in_array($code, ['4', '5', '7'], true) || ! empty($instance['isTaste'])) {
                continue;
            }
            $active[] = $instance;
        }
        $mainCard = (string) $customer->main_card;
        $hasAsset = $active !== [] || ! in_array($mainCard, ['', '—', '待同步卡项'], true);
        if (! $hasAsset) {
            return ['in' => false, 'codes' => []];
        }

        $liveCount = [];
        $liveTime = [];
        $unknown = [];
        $exhausted = false;
        $unactivated = 0;
        foreach ($active as $instance) {
            if ($instance['state'] === 'unactivated') {
                // HEAD 的分类循环遇到未开卡立即 `continue`：未开卡**不进入** liveCount/
                // liveTime/unknown/exhausted 四个集合（只影响「待开卡」计数断言）。
                // 重建时必须照做，否则「在用卡 + 未开卡」会被算成多卡合计而虚增 movedOut。
                $unactivated++;

                continue;
            }
            // HEAD 的期限卡残余恒 0（F1）：重建时强制归零，否则会复活 HEAD 的 liveTime
            $residue = $instance['type'] === CardLifecycle::TYPE_TIME
                ? 0
                : $instance['remaining'];
            if ($residue === null) {
                $unknown[] = $instance;

                continue;
            }
            if ($residue <= 0) {
                if ($instance['type'] !== CardLifecycle::TYPE_TIME) {
                    $exhausted = true;
                }

                continue;
            }
            $instance['remaining'] = $residue;
            if ($instance['type'] === CardLifecycle::TYPE_TIME) {
                $liveTime[] = $instance;
            } else {
                $liveCount[] = $instance;
            }
        }

        $why = [];
        if ($liveCount !== []) {
            $residueSum = array_sum(array_map(fn ($x) => (int) $x['remaining'], $liveCount));
            $boundSum = array_sum(array_map(fn ($x) => (int) ($x['bound'] ?? $x['remaining']), $liveCount));
            if ($residueSum <= $threshold) {
                $why[] = 'total';
            } elseif ($countPercent > 0 && $boundSum > 0 && $residueSum / $boundSum * 100 <= $countPercent) {
                $why[] = 'total_pct';
            }
            foreach ($liveCount as $card) {
                if ((int) $card['remaining'] <= $threshold) {
                    $why[] = 'card';
                    break;
                }
            }
        }
        if ($liveCount === [] && $exhausted) {
            $why[] = 'exhausted';
        }
        $pendingStart = $unactivated > 0 && $unactivated === count($active);
        if (! $pendingStart) {
            foreach (array_merge($liveCount, $liveTime) as $card) {
                $days = $card['daysUntil'];
                if ($days === null) {
                    continue;
                }
                if ($days >= 0 && $days <= $expireDays) {
                    $why[] = 'deadline_near';
                } elseif ($days < 0 && $days >= -$backfill) {
                    $why[] = 'deadline_expired';
                }
            }
            foreach ($unknown as $card) {
                if ($card['type'] !== CardLifecycle::TYPE_TIME) {
                    continue;
                }
                $days = $card['daysUntil'];
                if ($days === null) {
                    continue;
                }
                if ($days >= 0 && $days <= $expireDays) {
                    $why[] = 'deadline_near_unknown';
                } elseif ($days < 0 && $days >= -$backfill) {
                    $why[] = 'deadline_expired_unknown';
                }
            }
        }

        return [
            'in' => $hasAsset && $why !== [] && ! $pendingStart,
            'codes' => array_values(array_unique($why)),
        ];
    }

    /** 兼容包装：只关心布尔结论的调用方（历史行为）。 */
    private function legacyRenewalIn(Customer $customer, array $rules, array $instances): bool
    {
        return $this->legacyRenewal($customer, $rules, $instances)['in'];
    }
}
