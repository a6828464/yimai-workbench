<?php

namespace App\Services;

use App\Models\Lead;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * 到店 / 成交口径的唯一实现。
 *
 * ## 为什么要收敛到一处
 *
 * 「到店人数」与「成交率」此前有两个（后来是三个）独立实现：经营看板走 `AnalyticsController`，
 * 老师工作台的个人漏斗走 `TodayController::teacherOverview`。两处口径不一致，而且是**静默**的：
 *
 *  - 看板侧是三来源并集：预约系统已签到的体验课 ∪ 留资状态已体验/已成交 ∪ 体验课卡片勾了已上课；
 *  - 老师侧只认留资状态一条来源，于是「老师只勾了卡片、没把状态推进到已体验」的到店
 *    在老师看板里整个消失 —— 同一页面上老板和老师看到两个成交率。
 *
 * 每次修口径只改一处（v3.1.58 / v3.1.59 都只修了看板侧），问题就会反复出现。所以这里
 * 把「谁是到店、谁是成交」抽成一份实现，两个调用方都只传自己的**范围**（角色/门店/老师）。
 *
 * ## 口径（业务确认过）
 *
 *  - 按**人**去重：一个人来一次、两次、三次都只算一个人；
 *  - 成交率 = 到店体验过的人里成交的人数 ÷ 到店人数（分子分母同源，比率不会超过 100%）；
 *  - 身份键优先手机号（纯数字），没有手机号才退化成姓名。
 */
class VisitMetrics
{
    /** 身份键：优先归一后的手机号，缺失时退化为姓名（前缀区分，避免手机号与姓名撞键） */
    public static function identityOf($phone, string $fallback = ''): string
    {
        $digits = normalizePhone(is_string($phone) ? $phone : (string) $phone);
        if ($digits !== '') {
            return 'p:'.$digits;
        }

        return $fallback !== '' ? 'n:'.$fallback : '';
    }

    /**
     * 到店身份集合（三来源并集，按人去重）。
     *
     * 返回值结构保持逐字不变（`identities` / `sources`）—— 调用方 `AnalyticsController`
     * 与 `TodayController::teacherOverview` 都依赖它。实现委托给 `collectVisits()`，
     * 与「带日期的到店」共用同一份来源规则（避免再造第二份到店口径）。
     *
     * @param  Builder  $leadQ  已按角色/门店/老师收窄的留资查询（**不要**先按日期筛过，
     *                          三个来源各有自己的归期依据）
     * @param  iterable  $bookings  已按区间取出的 ky_bookings（需要 status / is_trial / venue / start_at 等列）
     * @return array{identities: array<string, true>, sources: array<string, array<string, true>>}
     */
    public static function visitSet(Builder $leadQ, iterable $bookings, string $start, string $end): array
    {
        $collected = self::collectVisits($leadQ, $bookings, $start, $end);

        return ['identities' => $collected['identities'], 'sources' => $collected['sources']];
    }

    /**
     * 到店身份集合 + **每人最早到店日期**（新媒体业绩的时效判定要「留资 ↔ 到店」配对，
     * 而 `visitSet()` 只给集合、不保留时间，无法判断是否越期）。
     *
     * 与 `visitSet()` 是同一份来源规则（同一 `collectVisits()`），只是多返回
     * `visitDates`：identity => 该人在区间内最早的到店日期（Y-m-d）。
     *
     * @return array{identities: array<string, true>, sources: array<string, array<string, true>>, visitDates: array<string, string>}
     */
    public static function visitPairs(Builder $leadQ, iterable $bookings, string $start, string $end): array
    {
        return self::collectVisits($leadQ, $bookings, $start, $end);
    }

    /**
     * 三来源扫描的**唯一实现**：`visitSet()` 与 `visitPairs()` 都走这里。
     *
     * @return array{identities: array<string, true>, sources: array<string, array<string, true>>, visitDates: array<string, string>}
     */
    private static function collectVisits(Builder $leadQ, iterable $bookings, string $start, string $end): array
    {
        $identities = [];
        $sources = ['booking' => [], 'leadStatus' => [], 'trialCard' => []];
        $visitDates = [];

        // $date 为可选的「该来源命中的事件日期」；同一人命中多个来源时取**最早**日期，
        // 因为业务要判「是否在时效内到店」，用最早到店对客户最有利、也最贴近事实。
        $mark = function (string $identity, string $source, string $date = '') use (&$identities, &$sources, &$visitDates): void {
            if ($identity === '') {
                return;
            }
            $identities[$identity] = true;
            $sources[$source][$identity] = true;
            if ($date !== '' && (! isset($visitDates[$identity]) || $date < $visitDates[$identity])) {
                $visitDates[$identity] = $date;
            }
        };

        // 来源 1：预约系统里已签到的体验课，按上课日期落在区间
        foreach ($bookings as $booking) {
            if (($booking->status ?? '') !== 'signed' || ! $booking->is_trial) {
                continue;
            }
            $mark(
                self::identityOf($booking->phone, (string) ($booking->member_id ?: $booking->member_name)),
                'booking',
                // 调用方已按区间取数；这里仍从 start_at 取真实到店日，供时效配对使用
                $booking->start_at ? substr((string) $booking->start_at, 0, 10) : ''
            );
        }

        // 来源 2：留资管理里已到店的客资。
        // 核销时间与留资日期只要有一个落在区间就算（两个条件是「或」）：上月买券、本月才到店
        // 的客人 redeemed_at 在区间外，而这类恰是线上到店的常见情形。
        $visitedLeads = (clone $leadQ)->whereIn('status', ['已体验', '已成交'])
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('redeemed_at', [$start.' 00:00:00', $end.' 23:59:59'])
                    ->orWhereBetween('lead_date', [$start, $end]);
            })->get(['phone', 'name', 'redeemed_at', 'lead_date']);
        foreach ($visitedLeads as $lead) {
            // 到店日优先取核销时间（真实到店事件），缺失才回退留资日期
            $mark(
                self::identityOf($lead->phone, (string) $lead->name),
                'leadStatus',
                $lead->redeemed_at?->toDateString() ?: (string) $lead->lead_date
            );
        }

        // 来源 3：体验课卡片勾了「已上课」，按卡片时间落在区间。
        // 老师常常只勾了卡片、没把整条留资的状态推进到「已体验」，只认 status 会把这类整批漏掉。
        $cardLeads = (clone $leadQ)->whereNotNull('trial_cards')->get(['phone', 'name', 'trial_cards']);
        foreach ($cardLeads as $lead) {
            foreach ((array) $lead->trial_cards as $card) {
                if (! is_array($card) || ! empty($card['cancelled']) || empty($card['attended'])) {
                    continue;
                }
                $day = substr((string) ($card['time'] ?? ''), 0, 10);
                if ($day === '' || $day < $start || $day > $end) {
                    continue;
                }
                $mark(self::identityOf($lead->phone, (string) $lead->name), 'trialCard', $day);
                break; // 同一人多张卡片只算一个人
            }
        }

        return ['identities' => $identities, 'sources' => $sources, 'visitDates' => $visitDates];
    }

    /**
     * 时效窗口的**末日**：留资月 + 之后 (validMonths-1) 个自然月的最后一天。
     *
     * 例（validMonths=2）：9.1 留资 → 10.31；9.30 留资 → 10.31（按**月**而非按天推移，
     * 所以月初与月末留资的窗口末日相同 —— 这正是用户确认的口径）。
     */
    public static function validityWindowEnd(string $leadDate, int $validMonths): ?string
    {
        if (trim($leadDate) === '') {
            return null;
        }
        $months = max(1, $validMonths);

        return CarbonImmutable::parse($leadDate)->startOfMonth()->addMonths($months)->subDay()->toDateString();
    }

    /**
     * 事件（到店/成交/核销）是否落在「留资月 + 下一个自然月」时效内。
     *
     * **失败关闭**（两个日期任一缺失 ⇒ 不算）：算钱的功能宁可少算不漏算；
     * 且缺失留资日的行本来也过不了 `isOnlineLead()`，口径自洽。
     * 事件早于留资（脏数据/先到店后补登记）同样不算 —— 它在业务上不满足
     * 「从留资起算的时效窗口」，计入会让窗口变成负数。
     */
    public static function isWithinValidity(?string $leadDate, ?string $eventDate, int $validMonths): bool
    {
        $lead = trim((string) $leadDate);
        $event = trim((string) $eventDate);
        if ($lead === '' || $event === '') {
            return false; // 无法核对 ⇒ 失败关闭
        }
        $end = self::validityWindowEnd($lead, $validMonths);
        if ($end === null) {
            return false;
        }

        return $event >= $lead && $event <= $end;
    }

    /**
     * 成交身份集合（只统计「到店体验过的人」里的成交，分子分母同源）。
     *
     * @param  array<string, true>  $visitIdentities  visitSet() 返回的身份集合
     * @return array{identities: array<string, true>, amount: float, count: int}
     */
    public static function dealSet(Builder $leadQ, array $visitIdentities, string $start, string $end): array
    {
        $collected = self::collectDeals($leadQ, $visitIdentities, $start, $end);

        return [
            'identities' => $collected['identities'],
            'amount' => $collected['amount'],
            'count' => $collected['count'],
        ];
    }

    /**
     * 成交身份集合 + **每人成交日期** + 金额（新媒体业绩的时效判定要「留资 ↔ 成交」配对）。
     *
     * 与 `dealSet()` 同一份实现（`collectDeals()`），只是多返回 `dealDates`。
     *
     * @param  array<string, true>  $visitIdentities
     * @return array{identities: array<string, true>, amount: float, count: int, dealDates: array<string, string>}
     */
    public static function dealPairs(Builder $leadQ, array $visitIdentities, string $start, string $end): array
    {
        return self::collectDeals($leadQ, $visitIdentities, $start, $end);
    }

    /**
     * 成交扫描的唯一实现：`dealSet()` 与 `dealPairs()` 都走这里。
     *
     * @param  array<string, true>  $visitIdentities
     * @return array{identities: array<string, true>, amount: float, count: int, dealDates: array<string, string>}
     */
    private static function collectDeals(Builder $leadQ, array $visitIdentities, string $start, string $end): array
    {
        // 成交按事件时间归期；历史数据没有 deal_at 时回退留资日期
        $sales = (clone $leadQ)->where('status', '已成交')
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('deal_at', [$start.' 00:00:00', $end.' 23:59:59'])
                    ->orWhere(fn ($q2) => $q2->whereNull('deal_at')->whereBetween('lead_date', [$start, $end]));
            })->get(['phone', 'name', 'deal_amount', 'deal_at', 'lead_date']);

        // 去重/金额/日期口径只在 dealSetFromLeads() 一处实现，这里只负责取数
        return self::dealSetFromLeads($sales, $visitIdentities);
    }

    /**
     * 内存版：已有一批「区间内成交」的留资时用它，避免重复查库。
     *
     * 也是**成交身份与金额口径的唯一实现**：`collectDeals()` 与 `AnalyticsController`
     * 的「留资登记成交」汇总都走它。入参对象可带 `deal_at` / `lead_date` 时，
     * 结果里会多出 `dealDates`（供时效配对），不带则与历史行为逐字一致。
     *
     * @param  iterable  $sales  已按区间取出的成交留资（需要 phone / name / deal_amount）
     * @param  array<string, true>  $visitIdentities
     * @return array{identities: array<string, true>, amount: float, count: int, dealDates?: array<string, string>}
     */
    public static function dealSetFromLeads(iterable $sales, array $visitIdentities): array
    {
        $identities = [];
        $amount = 0.0;
        $dealDates = [];
        foreach ($sales as $sale) {
            $identity = self::identityOf($sale->phone ?? '', (string) ($sale->name ?? ''));
            if ($identity === '' || ! isset($visitIdentities[$identity])) {
                continue; // 没到店过的人不计入成交 —— 分子分母同源
            }
            $identities[$identity] = true;
            $amount += (float) ($sale->deal_amount ?? 0);

            $date = isset($sale->deal_at) && $sale->deal_at
                ? (is_object($sale->deal_at) ? $sale->deal_at->toDateString() : substr((string) $sale->deal_at, 0, 10))
                : (string) ($sale->lead_date ?? '');
            if ($date !== '' && (! isset($dealDates[$identity]) || $date < $dealDates[$identity])) {
                $dealDates[$identity] = $date;
            }
        }

        return [
            'identities' => $identities,
            'amount' => $amount,
            'count' => count($identities),
            'dealDates' => $dealDates,
        ];
    }

    /** 该留资是否线上登记来源（复用全站口径，避免前端/后端各写一份正则） */
    public static function isOnlineLead(Lead $lead): bool
    {
        return isOnlineLead($lead);
    }
}
