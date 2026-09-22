<?php

namespace App\Services;

use App\Models\PayrollPerformance;
use App\Models\PayrollProfile;
use App\Support\PayrollMoney;
use App\Support\PayrollRoles;
use App\Support\XlsxReader;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 业绩表导入（`明细总表` sheet）。
 *
 * 口径来源：`03_计算程序与校验器/系统工具/standardize_payroll_sources.py`（S5）
 * 与 `统一工资计算引擎.py:85-98`（S1）。样表基准见规格 §3.6。
 *
 * ## 五个必须做对的点
 *
 * 1. **表头在第 2 行、实际只有 19 列**：样表 `<dimension ref="A1:XFD150"/>` 声明 16384 列。
 *    按声明宽度遍历会构造 16384 × 150 的矩阵；必须只认 XML 里真实出现的 `<c>`。
 * 2. **列一律按表头名定位**，禁止固定列号 —— 两店模板列号不同，固定列号会把会馆金额
 *    误当订单金额并制造虚假分配差异。
 * 3. **分配列区间** = 从 `金额` 列后一列到最后一个非空表头列，**排除 `备注`**。
 * 4. **299 活动卡必须剔除**：`收款类型` 含「299活动卡」的行不计个人提点、不计门店提成基数
 *    （S1:88-91）。样表 104/147 行、31,096.00。但**原始口径同时留档**，两套数都要返回。
 * 5. **一行可归属多人**：样表 3 行是一条交易归属两个销售员，必须逐列收集、不得只取第一列。
 */
class PayrollPerformanceImportService
{
    /** 标准列名（按表头名定位，不用列号） */
    private const COL_DATE = '日期';

    private const COL_MEMBER = '会员姓名';

    private const COL_PAYMENT_TYPE = '收款类型';

    private const COL_PAYMENT_METHOD = '收款方式';

    private const COL_AMOUNT = '金额';

    private const COL_VENUE = '会馆';

    private const COL_NOTE = '备注';

    private const SHEET_MAIN = '明细总表';

    /** 会馆归属行的「人员」标识（会馆列非空即计入门店整体业绩） */
    private const ALLOC_VENUE = PayrollPerformance::ALLOC_VENUE;

    private const ALLOC_PERSONAL = PayrollPerformance::ALLOC_PERSONAL;

    /** 非业绩 sheet：识别到就跳过并回 info，不导入也不报错（规格 §3.1） */
    private const NON_PERFORMANCE_SHEETS = ['瑜伽服饰销售登记表'];

    public function __construct(private PayrollNameResolver $resolver) {}

    /**
     * 解析业绩表（**不落库**）。
     *
     * @return array{payload:array,allocations:array,exceptions:array,notices:array,fatal:?string}
     */
    public function parse(string $path, string $venue, string $month, string $originalName = ''): array
    {
        $exceptions = [];
        $notices = [];

        $reader = XlsxReader::open($path);
        try {
            if (! $reader->hasSheet(self::SHEET_MAIN)) {
                return [
                    'payload' => [],
                    'allocations' => [],
                    'exceptions' => [[
                        'code' => 'INVALID_FILE',
                        'message' => '找不到 sheet「'.self::SHEET_MAIN.'」，无法导入',
                    ]],
                    'notices' => [],
                    'fatal' => 'INVALID_FILE',
                ];
            }

            // 识别并跳过非本月业绩 sheet（不导入、不报错，只回 info）
            foreach (self::NON_PERFORMANCE_SHEETS as $skip) {
                if ($reader->hasSheet($skip)) {
                    $notices[] = [
                        'level' => 'info',
                        'code' => 'SHEET_SKIPPED',
                        'message' => "已跳过非本月业绩 sheet：{$skip}",
                    ];
                }
            }

            $header = null;
            $headerRowNumber = 0;
            $dataRows = [];
            $maxCol = 0;
            foreach ($reader->rows(self::SHEET_MAIN) as $rowNumber => $cells) {
                // 表头在第 2 行：第 1 行是标题「一麦瑜伽2026年8月业绩明细」
                if ($rowNumber === 2) {
                    $header = [];
                    foreach ($cells as $col => $value) {
                        $header[$col] = trim((string) $value);
                    }
                    $headerRowNumber = $rowNumber;
                    $maxCol = $header === [] ? 0 : max(array_keys($header));
                    continue;
                }
                if ($rowNumber < 3) {
                    continue;
                }
                // 整行空 → 跳过
                $hasValue = false;
                foreach ($cells as $v) {
                    if ($v !== null && $v !== '') {
                        $hasValue = true;
                        break;
                    }
                }
                if (! $hasValue) {
                    continue;
                }
                $dataRows[] = [$rowNumber, $cells];
            }

            if ($header === null) {
                return [
                    'payload' => [],
                    'allocations' => [],
                    'exceptions' => [['code' => 'INVALID_FILE', 'message' => '第 2 行没有找到表头']],
                    'notices' => $notices,
                    'fatal' => 'INVALID_FILE',
                ];
            }

            // 按表头名定位关键列（禁止固定列号）
            $nameToCol = [];
            foreach ($header as $col => $name) {
                if ($name !== '' && ! isset($nameToCol[$name])) {
                    $nameToCol[$name] = $col;
                }
            }
            foreach ([self::COL_DATE, self::COL_AMOUNT] as $required) {
                if (! isset($nameToCol[$required])) {
                    return [
                        'payload' => [],
                        'allocations' => [],
                        'exceptions' => [[
                            'code' => 'INVALID_FILE',
                            'message' => "表头缺少「{$required}」列，无法导入",
                        ]],
                        'notices' => $notices,
                        'fatal' => 'INVALID_FILE',
                    ];
                }
            }

            $amountCol = $nameToCol[self::COL_AMOUNT];
            $venueCol = $nameToCol[self::COL_VENUE] ?? 0;
            $noteCol = $nameToCol[self::COL_NOTE] ?? 0;
            $dateCol = $nameToCol[self::COL_DATE];
            $memberCol = $nameToCol[self::COL_MEMBER] ?? 0;
            $paymentTypeCol = $nameToCol[self::COL_PAYMENT_TYPE] ?? 0;
            $paymentMethodCol = $nameToCol[self::COL_PAYMENT_METHOD] ?? 0;

            // 分配列区间：金额列后一列 → 最后一个非空表头列，
            // **排除「备注」，也必须排除「会馆」**。
            //
            // 会馆列（样表第 7 列）就夹在「金额」与销售员列之间，只按「排除备注」扫，
            // 它会被当成一个销售员列 —— 于是会馆归属行会**同时命中个人与会馆**，
            // 全部落进 ALLOCATION_MISMATCH 被拒（样表实测：venueRows 3 → 0、
            // exceptions 3、会馆合计 19,399.70 → 0）。
            $allocationCols = [];
            for ($col = $amountCol + 1; $col <= $maxCol; $col++) {
                $name = $header[$col] ?? '';
                if ($name === '' || $col === $noteCol || $col === $venueCol) {
                    continue;
                }
                $allocationCols[$col] = $name;
            }

            $allocations = [];
            $totals = [
                'dataRows' => 0,
                'personalRows' => 0,
                'venueRows' => 0,
                'skippedActivityCardRows' => 0,
                'skippedOutOfMonthRows' => 0,
                'skippedZeroAmountRows' => 0,
                'multiOwnerRows' => 0,
                'activityCardAmountCents' => 0,
                'rawPersonalCents' => 0,
                'rawVenueCents' => 0,
                'rawTotalCents' => 0,
                'commissionPersonalCents' => 0,
                'commissionStoreSalesCents' => 0,
            ];
            $byPerson = [];
            $storeSalesCounted = [];

            foreach ($dataRows as [$rowNumber, $cells]) {
                $totals['dataRows']++;
                $amountCents = PayrollMoney::cents($cells[$amountCol] ?? null);

                // 日期不在目标月份 → 跳过（正常跨月过滤，不算异常，S5:95）
                $dateRaw = $cells[$dateCol] ?? null;
                $date = $this->normalizeDate($dateRaw);
                if ($date === null) {
                    $exceptions[] = [
                        'code' => 'INVALID_DATE',
                        'row' => $rowNumber,
                        'message' => "第 {$rowNumber} 行日期无法识别：".(string) $dateRaw,
                    ];
                    continue;
                }
                if (! str_starts_with($date, $month)) {
                    $totals['skippedOutOfMonthRows']++;
                    continue;
                }
                if ($amountCents === 0) {
                    $totals['skippedZeroAmountRows']++;
                    continue;
                }

                $paymentType = trim((string) ($cells[$paymentTypeCol] ?? ''));
                $isActivityCard = str_contains($paymentType, '299活动卡');
                if ($isActivityCard) {
                    $totals['skippedActivityCardRows']++;
                    $totals['activityCardAmountCents'] += $amountCents;
                }

                // 逐列收集归属（一行可归属多人，不得只取第一列）
                $personal = [];
                foreach ($allocationCols as $col => $sourceName) {
                    $c = PayrollMoney::cents($cells[$col] ?? null);
                    if ($c !== 0) {
                        $personal[$col] = ['name' => $sourceName, 'cents' => $c];
                    }
                }
                $venueCents = $venueCol > 0 ? PayrollMoney::cents($cells[$venueCol] ?? null) : 0;

                // 归属互斥校验：同时命中 → ALLOCATION_MISMATCH，该行不导入
                if ($personal !== [] && $venueCents !== 0) {
                    $exceptions[] = [
                        'code' => 'ALLOCATION_MISMATCH',
                        'row' => $rowNumber,
                        'message' => "第 {$rowNumber} 行同时命中个人与会馆归属，无法判定，已跳过",
                        'personal' => PayrollMoney::toFloat(array_sum(array_column($personal, 'cents'))),
                        'venue' => PayrollMoney::toFloat($venueCents),
                        'amount' => PayrollMoney::toFloat($amountCents),
                    ];
                    continue;
                }

                // 无个人归属且会馆为空（但金额非 0）→ UNALLOCATED，不静默丢弃。
                //
                // ⚠️ 这一条必须**先于**下面的「分配不平」判定：金额 1000、一个归属都没有的行，
                // 同时满足两个条件，但它们是两种不同的事实 ——
                //   · UNALLOCATED：**一个归属都没有**，用户要回表补归属（可行动）
                //   · ALLOCATION_MISMATCH：**有归属但加起来对不上**，是表本身算错了
                // 顺序反了的话，前者会被误报成后者，用户拿到的提示从「去补归属」变成
                // 「分配不平」，查错方向完全不同（规格 §3.7 把 UNALLOCATED 单列）。
                if ($personal === [] && $venueCents === 0) {
                    $exceptions[] = [
                        'code' => 'UNALLOCATED',
                        'row' => $rowNumber,
                        'message' => "第 {$rowNumber} 行既无个人归属也无会馆归属，金额未计入任何口径，请回表补归属",
                        'amount' => PayrollMoney::toFloat($amountCents),
                        'memberName' => trim((string) ($cells[$memberCol] ?? '')),
                    ];
                    continue;
                }

                // 分配不平 → ALLOCATION_MISMATCH（S5:114 闸门），该行不导入
                $allocatedCents = array_sum(array_column($personal, 'cents')) + $venueCents;
                if (abs($amountCents - $allocatedCents) > 1) {
                    $exceptions[] = [
                        'code' => 'ALLOCATION_MISMATCH',
                        'row' => $rowNumber,
                        'message' => "第 {$rowNumber} 行分配不平：金额 ".PayrollMoney::fmt($amountCents)
                            .' ≠ Σ销售员 + 会馆 '.PayrollMoney::fmt($allocatedCents),
                        'amount' => PayrollMoney::toFloat($amountCents),
                        'allocated' => PayrollMoney::toFloat($allocatedCents),
                    ];
                    continue;
                }

                $transactionKey = implode('|', [
                    $date,
                    trim((string) ($cells[$memberCol] ?? '')),
                    PayrollMoney::fmt($amountCents),
                    $paymentType,
                ]);
                // 门店销售额 = 非 299 交易的**订单金额**，每条交易只计一次。
                // 用「已计集合」而不是「是否本交易第一条归属」：第一条归属可能因别名解析
                // 失败被跳过，用「第一条」判定会把这笔交易的销售额整笔丢掉。
                $countsStoreSales = ! $isActivityCard && ! isset($storeSalesCounted[$transactionKey]);
                if ($countsStoreSales) {
                    $storeSalesCounted[$transactionKey] = true;
                }
                // 门店销售额 = 该笔**交易**的订单金额，整笔只记一次。
                // 注意它必须落在**恰好一条归属行**上，而不是「本数据行的每条归属行」——
                // 一条交易可归属多个销售员（样表 3 行），若把金额写进该行的每条归属，
                // 门店销售额就会按归属条数翻倍（实测：364,167.20 → 411,418.20，
                // 多出的 47,251.00 正好是那 3 行多归属交易的金额之和）。
                $pendingStoreSales = $countsStoreSales ? PayrollMoney::toFloat($amountCents) : 0.0;
                $totals['commissionStoreSalesCents'] += $countsStoreSales ? $amountCents : 0;

                $totals['rawTotalCents'] += $amountCents;
                $base = [
                    'venue' => $venue,
                    'month' => $month,
                    'transaction_key' => mb_substr($transactionKey, 0, 64),
                    'occurred_on' => $date,
                    'member_name' => mb_substr(trim((string) ($cells[$memberCol] ?? '')), 0, 60),
                    'payment_type' => mb_substr($paymentType, 0, 60),
                    'payment_method' => mb_substr(trim((string) ($cells[$paymentMethodCol] ?? '')), 0, 40),
                    'transaction_amount' => PayrollMoney::toFloat($amountCents),
                    'is_activity_card' => $isActivityCard,
                    'source_row' => $rowNumber,
                ];

                if ($personal !== []) {
                    $totals['personalRows']++;
                    if (count($personal) > 1) {
                        $totals['multiOwnerRows']++;
                    }
                    foreach ($personal as $entry) {
                        $sourceName = $entry['name'];
                        $cents = $entry['cents'];
                        $profile = $this->resolver->resolve($sourceName);
                        if ($profile === null) {
                            // 别名解析失败 → UNMATCHED_NAME / AMBIGUOUS_NAME，保留原列名与该行金额，
                            // **不导入该归属**且不得静默丢弃
                            $ambiguous = $this->resolver->isAmbiguous($sourceName);
                            $exceptions[] = [
                                'code' => $ambiguous ? 'AMBIGUOUS_NAME' : 'UNMATCHED_NAME',
                                'row' => $rowNumber,
                                'sourceName' => $sourceName,
                                'message' => $ambiguous
                                    ? "销售员列名「{$sourceName}」命中多个人员，无法判定归属（宁可不认），该归属未导入"
                                    : "销售员列名「{$sourceName}」在薪酬档案/人员别名里对不上，该归属未导入",
                                'amount' => PayrollMoney::toFloat($cents),
                            ];
                            continue;
                        }
                        $allocations[] = $base + [
                            'allocation_type' => self::ALLOC_PERSONAL,
                            'payroll_profile_id' => $profile->id,
                            'user_id' => $profile->user_id,
                            'source_name' => mb_substr($sourceName, 0, 60),
                            'resolved_name' => $profile->name,
                            'raw_amount' => PayrollMoney::toFloat($cents),
                            'commission_amount' => PayrollMoney::toFloat($isActivityCard ? 0 : $cents),
                            // 门店销售额只记在**该交易实际落库的第一条归属行**上，
                            // 于是 `sum(store_sales_amount)` 天然等于门店销售额。
                            // 在「真正写入」时才消费 pending：若前面的归属因别名对不上被跳过，
                            // 这笔交易的销售额会顺延到下一条成功落库的归属上，不会整笔丢失。
                            'store_sales_amount' => $this->consumeStoreSales($pendingStoreSales),
                            'source_file_name' => mb_substr($originalName, 0, 160),
                            'source_sha256' => '',
                            'source_sheet' => self::SHEET_MAIN,
                        ];
                        $totals['rawPersonalCents'] += $cents;
                        if (! $isActivityCard) {
                            $totals['commissionPersonalCents'] += $cents;
                        }
                        $key = $profile->id;
                        $byPerson[$key] ??= [
                            'sourceName' => $sourceName,
                            'resolvedName' => $profile->name,
                            'userId' => $profile->user_id,
                            'profileId' => $profile->id,
                            'role' => $profile->role,
                            'rawAmountCents' => 0,
                            'commissionAmountCents' => 0,
                        ];
                        $byPerson[$key]['rawAmountCents'] += $cents;
                        if (! $isActivityCard) {
                            $byPerson[$key]['commissionAmountCents'] += $cents;
                        }
                    }
                }

                if ($venueCents !== 0) {
                    $totals['venueRows']++;
                    $allocations[] = $base + [
                        'allocation_type' => self::ALLOC_VENUE,
                        'payroll_profile_id' => null,
                        'user_id' => null,
                        'source_name' => self::COL_VENUE,
                        'resolved_name' => '',
                        'raw_amount' => PayrollMoney::toFloat($venueCents),
                        'commission_amount' => PayrollMoney::toFloat($isActivityCard ? 0 : $venueCents),
                        'store_sales_amount' => $this->consumeStoreSales($pendingStoreSales),
                        'source_file_name' => mb_substr($originalName, 0, 160),
                        'source_sha256' => '',
                        'source_sheet' => self::SHEET_MAIN,
                    ];
                    $totals['rawVenueCents'] += $venueCents;
                }
            }

            // 逐人档位与提成（逐笔 ROUND_HALF_UP 后累加 —— 引擎口径）
            $byPersonOut = [];
            $commissionTotalCents = 0;
            foreach ($byPerson as $item) {
                $profile = $this->resolver->allProfiles()[$item['profileId']] ?? null;
                $rate = $profile ? $profile->commissionRateFor($item['commissionAmountCents']) : '0';
                $commissionCents = PayrollMoney::mulFactor($item['commissionAmountCents'], $rate);
                $commissionTotalCents += $commissionCents;
                $byPersonOut[] = [
                    'sourceName' => $item['sourceName'],
                    'resolvedName' => $item['resolvedName'],
                    'userId' => $item['userId'],
                    'profileId' => $item['profileId'],
                    'role' => $item['role'],
                    'rawAmount' => PayrollMoney::toFloat($item['rawAmountCents']),
                    'commissionAmount' => PayrollMoney::toFloat($item['commissionAmountCents']),
                    'commissionRate' => (float) $rate,
                    'commission' => PayrollMoney::toFloat($commissionCents),
                ];
            }
            usort($byPersonOut, fn ($a, $b) => $b['commissionAmount'] <=> $a['commissionAmount']);

            $payload = [
                'venue' => $venue,
                'month' => $month,
                'sourceFileName' => $originalName,
                'sourceSha256' => hash_file('sha256', $path) ?: '',
                'counts' => [
                    'dataRows' => $totals['dataRows'],
                    'importedAllocations' => count($allocations),
                    'skippedActivityCardRows' => $totals['skippedActivityCardRows'],
                    'skippedOutOfMonthRows' => $totals['skippedOutOfMonthRows'],
                    'skippedZeroAmountRows' => $totals['skippedZeroAmountRows'],
                    'personalRows' => $totals['personalRows'],
                    'venueRows' => $totals['venueRows'],
                    'multiOwnerRows' => $totals['multiOwnerRows'],
                    'distinctPeople' => count($byPersonOut),
                    'exceptions' => count($exceptions),
                ],
                'totals' => [
                    // ① 原始归属口径（含 299 活动卡）—— 用于核对与留档
                    'raw' => [
                        'personal' => PayrollMoney::toFloat($totals['rawPersonalCents']),
                        'venue' => PayrollMoney::toFloat($totals['rawVenueCents']),
                        'total' => PayrollMoney::toFloat($totals['rawTotalCents']),
                        'activityCardAmount' => PayrollMoney::toFloat($totals['activityCardAmountCents']),
                    ],
                    // ② 提点口径（剔除 299）—— **提成与门店提成一律用这套**
                    'forCommission' => [
                        'personal' => PayrollMoney::toFloat($totals['commissionPersonalCents']),
                        'storeSales' => PayrollMoney::toFloat($totals['commissionStoreSalesCents']),
                        'commissionTotal' => PayrollMoney::toFloat($commissionTotalCents),
                    ],
                ],
                'byPerson' => $byPersonOut,
                'exceptions' => $exceptions,
                'notices' => $notices,
                'fatal' => null,
            ];

            return [
                'payload' => $payload,
                'allocations' => $allocations,
                'exceptions' => $exceptions,
                'notices' => $notices,
                'fatal' => null,
            ];
        } finally {
            $reader->close();
        }
    }

    /**
     * 提交导入：**按 (门店, 月份) 全量替换**。
     *
     * 不做按行 upsert —— 样表有 3 行是一行多归属，按行 upsert 无法表达「这条交易这次
     * 归属 2 人、下次归属 1 人」，会静默留下幽灵归属（规格 §3.5）。
     *
     * @param  array  $parseResult  `parse()` 的返回值
     * @param  string  $sha256  服务端重新解析得到的哈希（用于幂等判定与留档）
     */
    public function commit(array $parseResult, string $sha256, bool $allowExceptions = false): array
    {
        $payload = $parseResult['payload'];
        $allocations = $parseResult['allocations'];
        $venue = (string) $payload['venue'];
        $month = (string) $payload['month'];
        $exceptions = $parseResult['exceptions'];

        // 行级问题默认拒绝写入（除非显式 allowExceptions），异常行本身不入库（规格 §7.8）
        if ($exceptions !== [] && ! $allowExceptions) {
            throw new RuntimeException('COMMIT_HAS_EXCEPTIONS');
        }

        return DB::transaction(function () use ($payload, $allocations, $sha256, $venue, $month) {
            $existing = PayrollPerformance::query()->where('venue', $venue)->where('month', $month);
            $existingCount = (clone $existing)->count();
            $existingHash = (clone $existing)->orderBy('id')->value('source_sha256');

            // 指纹完全一致 → 不做写操作（幂等提示：同一份表重复导入金额不变）
            if ($existingCount > 0 && $existingHash !== null && $existingHash === $sha256) {
                $payload['unchanged'] = true;
                $payload['replaced'] = ['rows' => 0];
                $payload['dryRun'] = false;

                return $payload;
            }

            $existing->delete();

            $now = now();
            foreach (array_chunk($allocations, 200) as $chunk) {
                $rows = [];
                foreach ($chunk as $a) {
                    // ⚠️ 必须用 array_merge 而不是 `+`：`+` 是**并集**，左边已有的键
                    // 会赢。解析结果里带 `source_sha256 => ''`（导入前还不知道哈希），
                    // 用 `+` 会让空串覆盖掉这里的真实哈希，于是 `source_sha256` 永远是空、
                    // 幂等判定（existingHash === sha256）永远不成立 —— 实测踩过：
                    // 同一份表重复导入每次都当成「新文件」全量替换，unchanged 永远 false。
                    $rows[] = array_merge($a, [
                        'source_sha256' => $sha256,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
                if ($rows !== []) {
                    PayrollPerformance::insert($rows);
                }
            }

            $payload['unchanged'] = false;
            $payload['replaced'] = ['rows' => $existingCount];
            $payload['dryRun'] = false;
            $payload['sourceSha256'] = $sha256;

            return $payload;
        });
    }

    /**
     * 已导入业绩汇总（`GET /payroll/performance`）。
     */
    public function summary(string $month, ?string $venue = null): array
    {
        $base = PayrollPerformance::query()->where('month', $month)
            ->when($venue !== null, fn ($q) => $q->where('venue', $venue));

        $rawPersonal = 0;
        $rawVenue = 0;
        $commissionPersonal = 0;
        $storeSales = 0;
        $activityCard = 0;
        foreach ((clone $base)->get(['allocation_type', 'raw_amount', 'commission_amount', 'store_sales_amount', 'transaction_amount', 'is_activity_card']) as $r) {
            if ($r->allocation_type === self::ALLOC_PERSONAL) {
                $rawPersonal += PayrollMoney::cents($r->raw_amount);
                $commissionPersonal += PayrollMoney::cents($r->commission_amount);
            } else {
                $rawVenue += PayrollMoney::cents($r->raw_amount);
            }
            $storeSales += PayrollMoney::cents($r->store_sales_amount);
            if ($r->is_activity_card) {
                $activityCard += PayrollMoney::cents($r->transaction_amount);
            }
        }

        $byPerson = [];
        $rows = (clone $base)->where('allocation_type', self::ALLOC_PERSONAL)
            ->whereNotNull('payroll_profile_id')
            ->get(['payroll_profile_id', 'source_name', 'resolved_name', 'raw_amount', 'commission_amount']);
        foreach ($rows as $r) {
            $id = (int) $r->payroll_profile_id;
            $byPerson[$id] ??= [
                'profileId' => $id,
                'sourceName' => (string) $r->source_name,
                'resolvedName' => (string) $r->resolved_name,
                'rawAmountCents' => 0,
                'commissionAmountCents' => 0,
            ];
            $byPerson[$id]['rawAmountCents'] += PayrollMoney::cents($r->raw_amount);
            $byPerson[$id]['commissionAmountCents'] += PayrollMoney::cents($r->commission_amount);
        }
        $profiles = PayrollProfile::whereIn('id', array_keys($byPerson))->get()->keyBy('id');
        $out = [];
        foreach ($byPerson as $id => $item) {
            $p = $profiles[$id] ?? null;
            $rate = $p ? $p->commissionRateFor($item['commissionAmountCents']) : '0';
            $out[] = [
                'profileId' => $id,
                'userId' => $p?->user_id,
                'sourceName' => $item['sourceName'],
                'resolvedName' => $item['resolvedName'],
                'role' => $p?->role,
                'rawAmount' => PayrollMoney::toFloat($item['rawAmountCents']),
                'commissionAmount' => PayrollMoney::toFloat($item['commissionAmountCents']),
                'commissionRate' => (float) $rate,
                'commission' => PayrollMoney::toFloat(PayrollMoney::mulFactor($item['commissionAmountCents'], $rate)),
            ];
        }
        usort($out, fn ($a, $b) => $b['commissionAmount'] <=> $a['commissionAmount']);

        $meta = (clone $base)->orderBy('id')->first(['source_file_name', 'source_sha256', 'updated_at']);
        $allocCount = (clone $base)->count();

        return [
            'month' => $month,
            'venue' => $venue,
            'imported' => $allocCount > 0,
            'sourceFileName' => (string) ($meta->source_file_name ?? ''),
            'sourceSha256' => (string) ($meta->source_sha256 ?? ''),
            'importedAt' => optional($meta?->updated_at)->format('Y-m-d H:i:s'),
            'allocationCount' => $allocCount,
            'totals' => [
                'raw' => [
                    'personal' => PayrollMoney::toFloat($rawPersonal),
                    'venue' => PayrollMoney::toFloat($rawVenue),
                    'total' => PayrollMoney::toFloat($rawPersonal + $rawVenue),
                    'activityCardAmount' => PayrollMoney::toFloat($activityCard),
                ],
                'forCommission' => [
                    'personal' => PayrollMoney::toFloat($commissionPersonal),
                    'storeSales' => PayrollMoney::toFloat($storeSales),
                ],
            ],
            'byPerson' => $out,
            'venues' => PayrollRoles::VENUES,
        ];
    }

    /**
     * 消费「待记门店销售额」：取走后清零，保证同一笔交易只落一条。
     *
     * 用引用传参是因为它跨「个人归属循环」与「会馆归属分支」两处消费点，
     * 两条路径都可能成为该交易实际落库的第一条。
     */
    private function consumeStoreSales(float &$pending): float
    {
        $value = $pending;
        $pending = 0.0;

        return $value;
    }

    /** 兼容 Excel 序列号与字符串两种日期形态 */
    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return XlsxReader::excelSerialToDate((float) $value);
        }
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (is_numeric($s)) {
            return XlsxReader::excelSerialToDate((float) $s);
        }

        return null;
    }
}
