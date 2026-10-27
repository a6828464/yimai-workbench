<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * 卡项实例生命周期（v3.9 唯一派生口径）。
 *
 * 本类是 `docs/口径/待续费判定规范-v3.9.md` §2 的**唯一实现**：
 * `state()` / `remaining()` / `unit()` / `deadlineEligible()` 只在里定义一次，
 * 同步侧（KyMemberSyncService::summarizeCards）与判定侧（helpers::customerDecision）
 * 都调它。**禁止**在任一侧再写一份 if —— 两份实现必然分叉，而分叉的形态就是
 * 「同步认为在用、判定认为过期」这类最难查的缺陷。
 *
 * 三条来自【字段】的硬约束（违反即视为实现错误）：
 *  1. 期限卡（type=2）的剩余天数**只由 deadline 推导**，`residue_amount` 恒 0 不可读；
 *  2. `null`（未知）与 `0`（确认耗尽）严格区分，绝不归一；
 *  3. 节 / 天 / 元 永不跨单位相加，任何合计先按 unit 分组。
 */
final class CardLifecycle
{
    /** cards_list / card_stats 契约版本（§1.4、§7.2） */
    public const DATA_VERSION = 2;

    public const TYPE_COUNT = 1;   // 次卡，单位=节
    public const TYPE_TIME = 2;    // 期限卡，单位=天
    public const TYPE_STORED = 3;  // 储值卡，单位=元

    /** 体验/赠品卡标题关键词默认值（可被 rules.tasteTitlePattern 覆盖） */
    public const DEFAULT_TASTE_PATTERN = '体验|员工|测试|礼包|赠送';

    /** 上游 status → 中文（展示用） */
    public const STATUS_LABELS = ['5' => '正常', '6' => '过期', '7' => '未开卡', '4' => '请假', '29' => '退卡', '39' => '停卡'];

    /** 中文 status → 码（旧数据只有中文时还原） */
    public const STATUS_CODES = ['正常' => '5', '过期' => '6', '未开卡' => '7', '请假' => '4', '退卡' => '29', '停卡' => '39'];

    /** 历史状态 → historyReason */
    public const HISTORY_REASONS = ['6' => 'expired', '29' => 'refunded', '39' => 'suspended'];

    /** 上游 residue_amount_format 后缀 → 判定单位（仅用于 type 缺失时反推） */
    public const SUFFIX_UNITS = ['次' => '节', '节' => '节', '天' => '天', '个月' => '天', '元' => '元'];

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::today()->startOfDay();
    }

    /** 统一日期解析（Unix 时间戳 / Ymd / Y-m-d / 可解析字符串）；失败返回 null，绝不猜 */
    public static function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }
        if (is_numeric($value)) {
            $number = (int) $value;
            if ($number <= 0) {
                return null;
            }
            if ($number > 1000000000) {
                return CarbonImmutable::createFromTimestamp($number)->toDateString();
            }
            $text = (string) $number;
            if (strlen($text) === 8) {
                return CarbonImmutable::createFromFormat('Ymd', $text)->toDateString();
            }
        }
        try {
            return CarbonImmutable::parse((string) $value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /** 有符号天数：未来为正、过去为负、今天 0；无法解析返回 null */
    public static function daysUntilDate(?string $ymd): ?int
    {
        if ($ymd === null || $ymd === '') {
            return null;
        }
        try {
            return (int) self::today()->diffInDays(CarbonImmutable::parse($ymd)->startOfDay(), false);
        } catch (Throwable) {
            return null;
        }
    }

    /** 体验/赠品卡识别正则（配置为空时回落默认值；`0` 之类的非字符串也回落） */
    public static function tastePattern(?array $rules = null): string
    {
        $pattern = $rules['tasteTitlePattern'] ?? null;

        return is_string($pattern) && trim($pattern) !== '' ? trim($pattern) : self::DEFAULT_TASTE_PATTERN;
    }

    public static function matchesTaste(string $title, ?string $pattern = null): bool
    {
        $pattern = $pattern ?? self::DEFAULT_TASTE_PATTERN;
        if ($title === '' || $pattern === '') {
            return false;
        }

        return @preg_match('/('.$pattern.')/u', $title) === 1;
    }

    /**
     * 过期有余额展示窗（天）。读取顺序：expiredBalanceWindowDays → renewalExpiredBackfillDays → 90。
     * 逐键用 array_key_exists 判断，**不得**用 `?? 默认值` 回写：那会把合法的 0（=关闭）吞掉。
     */
    public static function expiredBalanceWindow(?array $rules = null): int
    {
        $rules ??= [];
        if (array_key_exists('expiredBalanceWindowDays', $rules) && is_numeric($rules['expiredBalanceWindowDays'])) {
            return max(0, (int) $rules['expiredBalanceWindowDays']);
        }
        if (array_key_exists('renewalExpiredBackfillDays', $rules) && is_numeric($rules['renewalExpiredBackfillDays'])) {
            return max(0, (int) $rules['renewalExpiredBackfillDays']);
        }

        return 90;
    }

    /** 取值（兼容字符串数字/带单位文本）；缺失返回 null（≠0） */
    private static function num(mixed $value): ?float
    {
        if ($value === null || $value === '' || is_bool($value) || is_array($value)) {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (preg_match('/-?\d+(\.\d+)?/', (string) $value, $m)) {
            return (float) $m[0];
        }

        return null;
    }

    private static function pick(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && $row[$key] !== '' && ! is_array($row[$key])) {
                return trim((string) $row[$key]);
            }
        }

        return null;
    }

    /**
     * 归一化一行实例（上游原始行 / cards_list / historyCards / expiredCards 均可）。
     *
     * 返回的键是判定层唯一读法：state/historyReason/daysUntil/remaining/remainingSource/unit/type…
     * 调用方**不得**再从原始行自行推断这些语义。
     *
     * @return array<string,mixed>
     */
    public static function normalize(array $row, ?string $tastePattern = null): array
    {
        $tastePattern ??= self::DEFAULT_TASTE_PATTERN;
        $degraded = [];
        // 同步侧已判定的降级码随实例落库（例如「期限卡 residue_amount 被忽略」发生在上游行上，
        // 判定层看到的 cards_list/historyCards 里 residue 已被纠正，无法自行复现该事实）。
        if (is_array($row['degraded'] ?? null)) {
            $degraded = array_values(array_filter($row['degraded'], 'is_string'));
        }

        $title = (string) (self::pick($row, ['title', 'card_title', 'card_name']) ?? '');

        // ── type（语义枚举，单位以它为准）─────────────────────────────
        $typeRaw = $row['type'] ?? null;
        $unitRaw = self::pick($row, ['unit']);
        if ($unitRaw === null || ! in_array($unitRaw, ['节', '天', '元'], true)) {
            $unitRaw = null;
        }
        if ($typeRaw !== null && $typeRaw !== '' && in_array((string) $typeRaw, ['1', '2', '3'], true)) {
            $type = (int) $typeRaw;
        } elseif ($unitRaw !== null) {
            $type = $unitRaw === '天' ? self::TYPE_TIME : ($unitRaw === '元' ? self::TYPE_STORED : self::TYPE_COUNT);
            $degraded[] = 'type_missing';
        } else {
            $suffix = self::SUFFIX_UNITS[(string) (self::pick($row, ['residueAmountFormat', 'residue_amount_format']) ?? '')] ?? null;
            $type = $suffix === '天' ? self::TYPE_TIME : ($suffix === '元' ? self::TYPE_STORED : self::TYPE_COUNT);
            $degraded[] = 'type_missing';
        }
        $unit = [self::TYPE_COUNT => '节', self::TYPE_TIME => '天', self::TYPE_STORED => '元'][$type];

        // ── 剩余量（三类型三口径，绝不互相借用）────────────────────────
        $residue = self::num($row['residue'] ?? $row['residue_amount'] ?? $row['residueAmount'] ?? null);
        $residue = $residue === null ? null : max(0.0, $residue);
        $residueDays = self::num($row['residueDays'] ?? $row['residue_days'] ?? null);
        $residueDays = $residueDays === null ? null : max(0.0, $residueDays);

        // ── 到期日（唯一权威；deadline_format 仅回退）───────────────────
        $deadline = self::parseDate($row['deadline'] ?? null) ?? self::parseDate($row['deadline_format'] ?? null);
        $daysUntil = self::daysUntilDate($deadline);

        // ── 状态码（numeric statusCode → numeric status → 中文映射）──────
        $statusLabel = null;
        $statusCode = null;
        $codeRaw = $row['statusCode'] ?? null;
        $statusRaw = $row['status'] ?? null;
        if ($codeRaw !== null && is_numeric($codeRaw)) {
            $statusCode = (string) (int) $codeRaw;
        } elseif ($statusRaw !== null && is_numeric($statusRaw)) {
            $statusCode = (string) (int) $statusRaw;
        } elseif (is_string($statusRaw) && $statusRaw !== '') {
            $statusLabel = $statusRaw;
            $statusCode = self::STATUS_CODES[$statusRaw] ?? null;
            if ($statusCode !== null) {
                $degraded[] = 'status_label_only';
            }
        }
        if ($statusCode !== null) {
            $statusLabel = self::STATUS_LABELS[$statusCode] ?? $statusLabel;
        }
        if ($statusCode === null && ! empty($row['unactivated'])) {
            $statusCode = '7';
            $statusLabel = self::STATUS_LABELS['7'];
        }

        // ── 身份字段 ────────────────────────────────────────────────
        $id = self::pick($row, ['id']);
        $cardNo = self::pick($row, ['cardNo', 'card_no']);
        $cardId = self::pick($row, ['cardId', 'card_id']);
        $createTime = self::parseDate($row['createTime'] ?? null)
            ?? self::parseDate($row['soldAt'] ?? null)
            ?? self::parseDate($row['create_time_format'] ?? null)
            ?? self::parseDate($row['create_time'] ?? null);
        $activateTime = self::parseDate($row['activateTime'] ?? null)
            ?? self::parseDate($row['activatedAt'] ?? null)
            ?? self::parseDate($row['activate_time'] ?? null);

        // ── 体验卡（字段是权威，标题正则是**追加**排除信号）──────────────────
        // D10-④：上游 `is_taste=0` 的「新人礼包2次」曾因旧硬编码正则漏掉「礼包」而进 P0。
        // 因此同步侧 = `is_taste=1` ∨ 标题命中 pattern；判定侧只读同步写好的 `isTaste`
        // （避免同一份正则在两处各编译一次、配置变更后两端口径不同）。
        $isTaste = false;
        $tasteReason = null;
        if (array_key_exists('isTaste', $row)) {
            $isTaste = (bool) $row['isTaste'];
            $tasteReason = $isTaste ? ($row['tasteReason'] ?? 'field') : null;
        } elseif (array_key_exists('is_taste', $row)) {
            $byField = (string) $row['is_taste'] === '1';
            $byPattern = self::matchesTaste($title, $tastePattern);
            $isTaste = $byField || $byPattern;
            $tasteReason = $byField ? 'field' : ($byPattern ? 'pattern' : null);
        } else {
            $isTaste = self::matchesTaste($title, $tastePattern);
            if ($isTaste) {
                $tasteReason = 'pattern';
                $degraded[] = 'taste_legacy_fallback';
            }
        }

        $state = self::stateOf($statusCode, $isTaste, $daysUntil);
        $historyReason = self::historyReasonOf($statusCode, $state, $daysUntil);

        // ── 有效余量（唯一表达式）───────────────────────────────────
        $remaining = null;
        $remainingSource = null;
        if ($type === self::TYPE_TIME) {
            if ($deadline !== null) {
                $remaining = (float) max(0, (int) $daysUntil);
                $remainingSource = 'deadline';
            } elseif ($residueDays !== null) {
                $remaining = floor($residueDays);
                $remainingSource = 'residue_days';
            }
        } elseif ($type === self::TYPE_STORED) {
            if ($residue !== null) {
                $remaining = round($residue, 2);
                $remainingSource = 'residue_amount';
            }
        } else {
            if ($residue !== null) {
                $remaining = floor($residue);
                $remainingSource = 'residue_amount';
            }
        }

        // ── 有效期总天数 = expiry_months*30 + expiry_days ───────────────
        $validDays = null;
        if (is_numeric($row['validDays'] ?? null)) {
            $validDays = (int) $row['validDays'];
        } else {
            $months = (int) (self::num($row['expiry_months'] ?? null) ?? 0);
            $days = (int) (self::num($row['expiry_days'] ?? null) ?? 0);
            $sum = $months * 30 + $days;
            $validDays = $sum > 0 ? $sum : null;
        }

        // 期限卡到期即失效：历史实例不存在「剩余天数」，remaining 必须与
        // 「期限卡到期即失效，剩余天数计为 0」的文案口径一致（A-10）。
        // 只在 state=history 时收敛，current/unactivated 的 deadline 推导不受影响。
        if ($type === self::TYPE_TIME && $state === 'history') {
            $remaining = 0.0;
            // deadline 缺失时连来源都无法判定（residue_days 只是 current 的回退，不是历史余量）
            $remainingSource = $deadline !== null ? 'deadline' : null;
        }

        // ── 降级：期限卡脏 residue / 缺 deadline、次卡缺剩余、状态缺失 ─────
        if ($type === self::TYPE_TIME) {
            if ($deadline === null) {
                $degraded[] = 'time_card_no_deadline';
            }
            // 只针对「仍以 residue_amount 表达余额」的脏数据；新 cards_list 的
            // type=2 residue 已经等于 deadline 推导值（residueSource=deadline），不得误报。
            // 同时要求 deadline 存在：无 deadline 时 residue_days 是**正常回退**，
            // 报「返回值与到期日不一致」文案与事实相反（A-10-①）。
            $source = self::pick($row, ['residueSource']);
            if ($deadline !== null && ($residue ?? 0) > 0 && $source !== 'deadline') {
                $degraded[] = 'time_card_residue_ignored';
            }
            // 规范 §5.3：有效期占比需要 validDays；缺失必须显式降级，否则
            // 「阈值调了没反应」无从排查（A-02）。
            // 只对参与规则的实例报（current/unactivated）：历史实例不进命中域，
            // 给它报「占比规则未生效」只会制造噪音。
            if ($validDays === null && $state !== 'history') {
                $degraded[] = 'valid_days_missing';
            }
        }
        if ($type === self::TYPE_COUNT && $remaining === null) {
            $degraded[] = 'count_residue_unknown';
        }
        if ($statusCode === null) {
            $degraded[] = 'status_missing';
        }
        if ($statusCode === '7' && $daysUntil !== null && $daysUntil < 0) {
            $degraded[] = 'unactivated_expired';
        }
        if ($state === 'history' && $deadline === null) {
            $degraded[] = 'history_without_deadline';
        }

        $identity = $id !== null && $id !== ''
            ? 'id:'.$id
            : ($cardNo !== null && $cardNo !== ''
                ? 'no:'.$cardNo
                // hash 兜底必须把「能区分两张卡」的字段都算进去；否则两张同标题、同类型、
                // 同到期日的无身份卡会得到同一 hash，被下游去重错误合并（A15/R12 的降级形态）。
                : 'hash:'.sha1(implode('|', [
                    $title, (string) $deadline, (string) $createTime, (string) $type,
                    (string) $residue, (string) $residueDays, (string) $statusCode,
                ])));
        if (! str_starts_with($identity, 'id:') && ! str_starts_with($identity, 'no:')) {
            $degraded[] = 'identity_missing';
        }

        return [
            'id' => $id,
            'cardNo' => $cardNo,
            'cardId' => $cardId,
            'title' => $title,
            'type' => $type,
            'unit' => $unit,
            'residue' => $residue,
            'residueDays' => $residueDays,
            'deadline' => $deadline,
            'daysUntil' => $daysUntil,
            'remaining' => $remaining,
            'remainingSource' => $remainingSource,
            'validDays' => $validDays,
            'bound' => is_numeric($row['bound'] ?? null) ? (float) $row['bound'] : null,
            'statusCode' => $statusCode,
            'statusLabel' => $statusLabel,
            'state' => $state,
            'historyReason' => $historyReason,
            'createTime' => $createTime,
            'activateTime' => $activateTime,
            'isTaste' => $isTaste,
            'tasteReason' => $tasteReason,
            'canOp' => self::pick($row, ['canOp', 'can_op']),
            'identity' => $identity,
            'degraded' => array_values(array_unique($degraded)),
        ];
    }

    /** §2.3 状态判定唯一表达式（顺序即优先级，互斥且穷尽） */
    public static function stateOf(?string $statusCode, bool $isTaste, ?int $daysUntil): string
    {
        if ($isTaste) {
            return 'excluded';
        }
        if (in_array($statusCode, ['29', '39', '6'], true)) {
            return 'history';
        }
        if ($daysUntil !== null && $daysUntil < 0) {
            return 'history';
        }
        if ($statusCode === '7') {
            return 'unactivated';
        }
        if (in_array($statusCode, ['4', '5'], true)) {
            return 'current';
        }
        if ($statusCode === null) {
            return 'current';
        }

        // 未知码：保守按历史处理（不进待续费，也不当作可用权益）
        return 'history';
    }

    public static function historyReasonOf(?string $statusCode, string $state, ?int $daysUntil): ?string
    {
        if ($state !== 'history') {
            return null;
        }
        $reason = self::HISTORY_REASONS[$statusCode] ?? null;
        if ($reason !== null) {
            return $reason;
        }

        return ($daysUntil !== null && $daysUntil < 0) ? 'expired_by_clock' : 'expired';
    }

    public static function isUsable(array $i): bool
    {
        return $i['state'] === 'current' && $i['remaining'] !== null && $i['remaining'] > 0;
    }

    public static function isExhausted(array $i): bool
    {
        return $i['state'] === 'current' && $i['type'] === self::TYPE_COUNT && $i['remaining'] === 0.0;
    }

    public static function isUnknown(array $i): bool
    {
        return $i['state'] === 'current' && $i['remaining'] === null;
    }

    public static function isLastDay(array $i): bool
    {
        return $i['state'] === 'current' && $i['type'] === self::TYPE_TIME && $i['daysUntil'] === 0;
    }

    public static function isDue(array $i, int $expireDays): bool
    {
        return $i['deadline'] !== null && $i['daysUntil'] !== null
            && $i['daysUntil'] >= 0 && $i['daysUntil'] <= $expireDays;
    }

    /**
     * 到期规则的适配域（§2.4）：次卡必须还有余量（空卡临期不提醒），
     * 期限卡最后一天（daysUntil=0、remaining=0）单独放行，否则年卡到期当天会整条消失。
     */
    public static function deadlineEligible(array $i, int $expireDays): bool
    {
        return $i['state'] === 'current'
            && in_array($i['type'], [self::TYPE_COUNT, self::TYPE_TIME], true)
            && $i['deadline'] !== null
            && self::isDue($i, $expireDays)
            && (self::isUsable($i) || self::isLastDay($i));
    }

    /** 历史实例的「残余量」：期限卡到期即失效，恒为 0（定义，不是缺失） */
    public static function residualBalance(array $i): float
    {
        if ($i['type'] === self::TYPE_TIME) {
            return 0.0;
        }

        return $i['remaining'] === null ? 0.0 : (float) $i['remaining'];
    }

    public static function withinExpiredWindow(array $i, int $window): bool
    {
        if ($window <= 0 || $i['deadline'] === null || $i['daysUntil'] === null) {
            return false;
        }

        return $i['daysUntil'] < 0 && (-$i['daysUntil']) <= $window;
    }

    /** 系列键：cardId 优先，缺失退化为标题（仅展示分组，永不作为身份） */
    public static function seriesKey(array $i): string
    {
        if (($i['cardId'] ?? null) !== null && $i['cardId'] !== '') {
            return 'p:'.$i['cardId'];
        }

        return 't:'.preg_replace('/\s+/u', '', (string) $i['title']);
    }

    /**
     * 给每个实例写入**唯一**的系列键与序号（A-01）。
     *
     * 为什么不能再返回 `identity => [index,count]` 的 map：`identity` 在缺 id/card_no 的
     * 旧数据上会退化为 hash，同字段的两张卡得到同一个 hash —— 用 identity 当数组键会把
     * 多张卡折叠成一张（余额少算、归因全写「同名第 k 张」）。
     *
     * 现在改为：
     *  - `instanceKey` = identity + **出现序号**（全局按入参顺序计数，保证唯一）；
     *  - `seriesIndex` / `seriesCount` 直接挂在实例上，suffix() 只读实例，不再查 map。
     *
     * @param  array<int,array>  $instances
     * @return array<int,array> 同序返回，带 instanceKey/seriesIndex/seriesCount
     */
    public static function annotateSeries(array $instances): array
    {
        // ① 唯一实例键：identity + 第 n 次出现（hash 身份相同时也不会撞）
        $seen = [];
        foreach ($instances as $idx => $instance) {
            $identity = (string) ($instance['identity'] ?? '');
            $n = ($seen[$identity] ?? 0) + 1;
            $seen[$identity] = $n;
            $instances[$idx]['instanceKey'] = $identity.'#'.$n;
        }

        // ② 系列分组 + 稳定排序（按 createTime → cardNo → deadline 升序）
        $groups = [];
        foreach ($instances as $idx => $instance) {
            $groups[self::seriesKey($instance)][] = $idx;
        }
        foreach ($groups as $indexes) {
            usort($indexes, function (int $a, int $b) use ($instances) {
                $left = $instances[$a];
                $right = $instances[$b];
                $cmp = strcmp((string) ($left['createTime'] ?? ''), (string) ($right['createTime'] ?? ''));
                if ($cmp !== 0) {
                    return $cmp;
                }
                $cmp = strcmp((string) ($left['cardNo'] ?? ''), (string) ($right['cardNo'] ?? ''));
                if ($cmp !== 0) {
                    return $cmp;
                }

                return strcmp((string) ($left['deadline'] ?? '9999-12-31'), (string) ($right['deadline'] ?? '9999-12-31'));
            });
            $count = count($indexes);
            foreach ($indexes as $position => $idx) {
                $instances[$idx]['seriesIndex'] = $position + 1;
                $instances[$idx]['seriesCount'] = $count;
            }
        }

        return $instances;
    }

    /**
     * 兼容包装：旧调用方按 `identity` 取序号的入口。
     *
     * @deprecated A-01 起请用 annotateSeries() 并把 seriesIndex 挂到实例上；
     *             本方法以 instanceKey 为键（不再是 identity），避免 hash 撞键。
     *
     * @return array<string,array{index:int,count:int,instanceKey:string}>
     */
    public static function seriesIndexes(array $instances): array
    {
        $out = [];
        foreach (self::annotateSeries($instances) as $instance) {
            $out[$instance['instanceKey']] = [
                'index' => $instance['seriesIndex'],
                'count' => $instance['seriesCount'],
                'instanceKey' => $instance['instanceKey'],
            ];
        }

        return $out;
    }

    /** 身份后缀（§4.4）：同名第 k 张 · 卡号/发卡/到期 · 状态 */
    public static function suffix(array $i, ?array $series = null): string
    {
        // 优先读实例自身携带的系列信息（annotateSeries 写入）；$series 仅保留旧调用方式。
        $seriesIndex = $i['seriesIndex'] ?? ($series['index'] ?? null);
        $seriesCount = $i['seriesCount'] ?? ($series['count'] ?? null);
        $parts = [];
        if ($seriesCount !== null && (int) $seriesCount > 1 && $seriesIndex !== null) {
            $parts[] = '同名第 '.(int) $seriesIndex.' 张';
        }
        // 身份后缀（§4.4 示例：`同名第 1 张 · 卡号 … · 发卡 …`）：
        // 卡号与发卡日**同时给出**时都写出来，归因才真正可区分；
        // 三者全缺 → 空后缀 + degraded identity_missing。
        if (($i['cardNo'] ?? null) !== null && $i['cardNo'] !== '') {
            $parts[] = '卡号 '.$i['cardNo'];
        }
        if (($i['createTime'] ?? null) !== null) {
            $parts[] = '发卡 '.$i['createTime'];
        }
        if ($parts === [] && ($i['deadline'] ?? null) !== null) {
            $parts[] = '到期 '.$i['deadline'];
        }
        $stateToken = match (true) {
            $i['state'] === 'history' => '历史',
            $i['state'] === 'unactivated' => '未开卡',
            self::isExhausted($i) => '已用完',
            default => '',
        };
        if ($stateToken !== '') {
            $parts[] = $stateToken;
        }

        return $parts === [] ? '' : '（'.implode(' · ', $parts).'）';
    }

    public static function fmtQty(float|int|null $value, string $unit): string
    {
        $value ??= 0;

        return match ($unit) {
            '元' => number_format((float) $value, 2).' 元',
            '天' => ((int) $value).' 天',
            '节' => ((int) $value).' 节',
            default => ((int) $value).' 份',
        };
    }

    /**
     * 当前实例排序键（§2.6）。数组可直接用 <=> 逐项比较（PHP 数组比较按值顺序）。
     *
     * @return array{0:int,1:int,2:int,3:int,4:string,5:string}
     */
    public static function sortKey(array $i): array
    {
        $rank = self::isUsable($i) ? 3 : ($i['state'] === 'unactivated' ? 2 : 1);
        $codePriority = ['5' => 3, '4' => 2, '7' => 1][(string) ($i['statusCode'] ?? '')] ?? 0;

        return [
            -$rank,
            -$codePriority,
            $i['daysUntil'] === null ? 999999 : (int) $i['daysUntil'],
            $i['remaining'] === null ? 999999 : (int) $i['remaining'],
            (string) ($i['createTime'] ?? '9999-12-31'),
            (string) ($i['cardNo'] ?? ''),
        ];
    }

    /** 按 sortKey 找主卡（current ∪ unactivated 优先；无当前时回退最近历史） */
    public static function mainCardFrom(array $instances): array
    {
        $currentish = array_values(array_filter($instances, fn ($i) => in_array($i['state'], ['current', 'unactivated'], true)));
        if ($currentish !== []) {
            usort($currentish, fn ($a, $b) => self::sortKey($a) <=> self::sortKey($b));

            return [$currentish[0], $currentish[0]['state']];
        }
        $history = array_values(array_filter($instances, fn ($i) => $i['state'] === 'history'));
        if ($history !== []) {
            usort($history, function ($a, $b) {
                $cmp = strcmp((string) ($b['createTime'] ?? ''), (string) ($a['createTime'] ?? ''));
                if ($cmp !== 0) {
                    return $cmp;
                }

                return strcmp((string) ($b['cardNo'] ?? ''), (string) ($a['cardNo'] ?? ''));
            });

            return [$history[0], 'history'];
        }

        return [null, 'none'];
    }

    /** 唯一汇总口径（§2.6）：到期日只由当前实例决定，历史永不把「当前」拉早 */
    public static function expireDateFrom(array $instances): array
    {
        $deadlines = [];
        foreach ($instances as $i) {
            if ($i['state'] === 'current' && $i['deadline'] !== null) {
                $deadlines[] = $i['deadline'];
            }
        }
        if ($deadlines !== []) {
            sort($deadlines);

            return [$deadlines[0], 'current'];
        }
        $historyDeadlines = [];
        foreach ($instances as $i) {
            if ($i['state'] === 'history' && $i['deadline'] !== null) {
                $historyDeadlines[] = $i['deadline'];
            }
        }
        if ($historyDeadlines !== []) {
            rsort($historyDeadlines);

            return [$historyDeadlines[0], 'history'];
        }

        return [null, 'none'];
    }

    /** 历史实例的说明文案（§5.4；只用于 expiredBalance / historyCards，不进 renewal.why） */
    public static function historyNote(array $i, ?array $series = null): ?string
    {
        $suffix = self::suffix($i, $series);
        $title = (string) $i['title'] !== '' ? $i['title'] : '卡项';
        $reason = $i['historyReason'];
        $qty = self::fmtQty(self::residualBalance($i), $i['unit']);
        $expiredDays = $i['daysUntil'] === null ? null : abs((int) $i['daysUntil']);

        if ($reason === 'suspended') {
            return "「{$title}」停卡中，权益 {$qty} 已冻结（可解除）{$suffix}";
        }
        if ($reason === 'refunded') {
            return "「{$title}」已退卡，残余 {$qty} 为退款残留（不计入过期余额）{$suffix}";
        }
        if ($i['type'] === self::TYPE_TIME) {
            // A-10：缺 deadline 时不能写出「已于  到期」这种空日期文案。
            if ($i['deadline'] === null) {
                return "「{$title}」到期日未知（期限卡已失效）{$suffix}";
            }

            return "「{$title}」已于 {$i['deadline']} 到期（期限卡到期即失效，剩余天数计为 0）{$suffix}";
        }
        if ($expiredDays !== null) {
            return "「{$title}」已过期 {$expiredDays} 天，余 {$qty}（仅提示，不触发待续费）{$suffix}";
        }

        return null;
    }
}
