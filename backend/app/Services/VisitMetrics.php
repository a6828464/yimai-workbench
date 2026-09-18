<?php

namespace App\Services;

use App\Models\Lead;
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
     * @param  Builder  $leadQ  已按角色/门店/老师收窄的留资查询（**不要**先按日期筛过，
     *                          三个来源各有自己的归期依据）
     * @param  iterable  $bookings  已按区间取出的 ky_bookings（需要 status / is_trial / venue / start_at 等列）
     * @return array{identities: array<string, true>, sources: array<string, array<string, true>>}
     */
    public static function visitSet(Builder $leadQ, iterable $bookings, string $start, string $end): array
    {
        $identities = [];
        $sources = ['booking' => [], 'leadStatus' => [], 'trialCard' => []];

        $mark = function (string $identity, string $source) use (&$identities, &$sources): void {
            if ($identity === '') {
                return;
            }
            $identities[$identity] = true;
            $sources[$source][$identity] = true;
        };

        // 来源 1：预约系统里已签到的体验课，按上课日期落在区间
        foreach ($bookings as $booking) {
            if (($booking->status ?? '') !== 'signed' || ! $booking->is_trial) {
                continue;
            }
            $mark(
                self::identityOf($booking->phone, (string) ($booking->member_id ?: $booking->member_name)),
                'booking'
            );
        }

        // 来源 2：留资管理里已到店的客资。
        // 核销时间与留资日期只要有一个落在区间就算（两个条件是「或」）：上月买券、本月才到店
        // 的客人 redeemed_at 在区间外，而这类恰是线上到店的常见情形。
        $visitedLeads = (clone $leadQ)->whereIn('status', ['已体验', '已成交'])
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('redeemed_at', [$start.' 00:00:00', $end.' 23:59:59'])
                    ->orWhereBetween('lead_date', [$start, $end]);
            })->get(['phone', 'name']);
        foreach ($visitedLeads as $lead) {
            $mark(self::identityOf($lead->phone, (string) $lead->name), 'leadStatus');
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
                $mark(self::identityOf($lead->phone, (string) $lead->name), 'trialCard');
                break; // 同一人多张卡片只算一个人
            }
        }

        return ['identities' => $identities, 'sources' => $sources];
    }

    /**
     * 成交身份集合（只统计「到店体验过的人」里的成交，分子分母同源）。
     *
     * @param  array<string, true>  $visitIdentities  visitSet() 返回的身份集合
     * @return array{identities: array<string, true>, amount: float, count: int}
     */
    public static function dealSet(Builder $leadQ, array $visitIdentities, string $start, string $end): array
    {
        // 成交按事件时间归期；历史数据没有 deal_at 时回退留资日期
        $sales = (clone $leadQ)->where('status', '已成交')
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('deal_at', [$start.' 00:00:00', $end.' 23:59:59'])
                    ->orWhere(fn ($q2) => $q2->whereNull('deal_at')->whereBetween('lead_date', [$start, $end]));
            })->get(['phone', 'name', 'deal_amount']);

        return self::dealSetFromLeads($sales, $visitIdentities);
    }

    /**
     * 内存版：已有一批「区间内成交」的留资时用它，避免重复查库。
     *
     * @param  iterable  $sales  已按区间取出的成交留资（需要 phone / name / deal_amount）
     * @param  array<string, true>  $visitIdentities
     * @return array{identities: array<string, true>, amount: float, count: int}
     */
    public static function dealSetFromLeads(iterable $sales, array $visitIdentities): array
    {
        $identities = [];
        $amount = 0.0;
        foreach ($sales as $sale) {
            $identity = self::identityOf($sale->phone ?? '', (string) ($sale->name ?? ''));
            if ($identity === '' || ! isset($visitIdentities[$identity])) {
                continue; // 没到店过的人不计入成交 —— 分子分母同源
            }
            $identities[$identity] = true;
            $amount += (float) ($sale->deal_amount ?? 0);
        }

        return ['identities' => $identities, 'amount' => $amount, 'count' => count($identities)];
    }

    /** 该留资是否线上登记来源（复用全站口径，避免前端/后端各写一份正则） */
    public static function isOnlineLead(Lead $lead): bool
    {
        return isOnlineLead($lead);
    }
}
