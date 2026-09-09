<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\KyBooking;
use App\Models\KyCard;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class AnalyticsController extends Controller
{
    /**
     * 看板聚合缓存键：按角色范围 + 查询参数隔离，版本号随业务写入失效，TTL 60 秒兜底。
     */
    private static function cacheKey(string $endpoint, User $u, string $venue, string $start, string $end): string
    {
        $scope = match ($u->role) {
            'R_SUPER' => 'all',
            'R_MEDIA' => implode(',', (array) $u->venues),
            default => (string) $u->venue,
        };

        return 'analytics:v'.businessCacheVersion('analytics').":{$endpoint}:".md5("{$scope}|{$venue}|{$start}|{$end}");
    }

    /** GET /analytics/summary */
    public function summary(Request $r)
    {
        $u = $r->user();

        return ok(Cache::remember(
            self::cacheKey('summary', $u, '', '', ''),
            60,
            function () use ($u) {
                return $this->computeSummary($u);
            }
        ));
    }

    private function computeSummary($u): array
    {
        $custQ = Customer::query();
        if ($u->role !== 'R_SUPER') {
            $custQ->where('venue', $u->venue);
        }
        $customers = $custQ->get();
        $totalMembers = $customers->filter(fn ($c) => $c->layer !== 'P5' || str_starts_with((string) $c->external_id, 'ky:'))->count();
        // 待分配以随心瑜顾问字段为准，本地负责人只是后续执行归属。
        $unassigned = $customers->filter(fn ($c) => trim((string) $c->consultant) === '')->count();

        $leadQ = applyVenueScope(Lead::query(), $u, '');
        $leads = $leadQ->get();
        $newLeads = $leads->where('status', '新留资')->count();
        $leadsWithTeacher = $leads->filter(fn ($l) => $l->service_teacher !== '')->count();
        $assignRate = $leads->count() > 0 ? round($leadsWithTeacher / $leads->count() * 100) : 0;

        $custWithAction = $customers->filter(fn ($c) => $c->owner !== '未分配' && $c->next_action !== null && $c->next_action !== '')->count();
        $closureRate = $totalMembers > 0 ? round($custWithAction / $totalMembers * 100) : 0;

        $renewalIds = filteredIds('待续课');
        $renewing = $customers->whereIn('id', $renewalIds);
        $renewalTouched = $renewing->filter(fn ($c) => $c->renewal_plan !== null && $c->renewal_plan !== '')->count();
        $renewalRate = $renewing->count() > 0 ? round($renewalTouched / $renewing->count() * 100) : 0;

        $taskQ = applyVenueScope(Task::query(), $u, '');
        $tasks = $taskQ->get();
        $taskRate = $tasks->count() > 0 ? round($tasks->where('status', '已完成')->count() / $tasks->count() * 100) : 0;

        return [
            'totalCustomers' => $customers->count(),
            'totalMembers' => $totalMembers,
            'unassigned' => $unassigned,
            'assignRate' => $assignRate,           // 留资分配率
            'closureRate' => $closureRate,          // 客户闭环率
            'renewalTasks' => $renewing->count(),   // 待续课数
            'renewalRate' => $renewalRate,          // 续费已处理率
            'taskRate' => $taskRate,                // 任务完成率
            'doneTasks' => $tasks->where('status', '已完成')->count(),
            'totalTasks' => $tasks->count(),
        ];
    }

    /** GET /analytics/trends */
    public function trends(Request $r)
    {
        $u = $r->user();
        $venue = (string) $r->query('venue', '');
        abort_unless($venue === '' || in_array($venue, ['绿地店', '东部店'], true), 422, '门店参数无效');
        $start = $r->query('start') ?: now()->startOfMonth()->toDateString();
        $end = $r->query('end') ?: now()->toDateString();

        return ok(Cache::remember(
            self::cacheKey('trends', $u, $venue, $start, $end),
            60,
            fn () => $this->computeTrends($u, $venue, $start, $end)
        ));
    }

    private function computeTrends($u, string $venue, string $start, string $end): array
    {
        $leadQ = applyVenueScope(Lead::query(), $u, $venue);

        $leads = (clone $leadQ)->whereBetween('lead_date', [$start, $end])->get();

        $byDate = [];
        $bucket = function (string $date, string $venue, $l) use (&$byDate): void {
            $byDate[$date][$venue]['leads'] = ($byDate[$date][$venue]['leads'] ?? 0) + 1;
            // 已约体验及以上 → 计入约客/预约
            if (in_array($l->status, ['已约体验', '已体验', '已成交'], true)) {
                $byDate[$date][$venue]['booked'] = ($byDate[$date][$venue]['booked'] ?? 0) + 1;
            }
            // 已体验/已成交 → 计入体验
            if (in_array($l->status, ['已体验', '已成交'], true)) {
                $byDate[$date][$venue]['experienced'] = ($byDate[$date][$venue]['experienced'] ?? 0) + 1;
            }
        };
        foreach ($leads as $l) {
            $d = (string) $l->lead_date;
            $bucket($d, $l->venue ?: '双店', $l);
        }

        // 售卡按成交发生时间统计；历史数据没有事件时间时兼容回退留资日期（日期下推 SQL，不再全历史拉取后 PHP 过滤）。
        $sales = (clone $leadQ)->where('status', '已成交')
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('deal_at', [$start.' 00:00:00', $end.' 23:59:59'])
                    ->orWhere(fn ($q2) => $q2->whereNull('deal_at')->whereBetween('lead_date', [$start, $end]));
            })->get();
        foreach ($sales as $sale) {
            $date = $sale->deal_at?->toDateString() ?: (string) $sale->lead_date;
            $saleVenue = $sale->venue ?: '双店';
            $byDate[$date][$saleVenue]['deals'] = ($byDate[$date][$saleVenue]['deals'] ?? 0) + 1;
        }

        // 售卡张数/金额：以随心瑜会员卡表落库的售卡事实为准，按售卡时间与实收金额统计，
        // 排除体验/赠卡（is_taste）与退卡；不再依赖工作台留资手动登记的成交卡项。
        $cardQ = applyVenueScope(
            KyCard::query()
                ->where('is_taste', false)
                ->where('status_format', '!=', '退卡')
                ->whereNotNull('sold_at')
                ->whereBetween('sold_at', [$start, $end]),
            $u, $venue
        );
        foreach ($cardQ->get() as $card) {
            $date = (string) $card->sold_at;
            $cardVenue = $card->venue ?: '双店';
            $byDate[$date][$cardVenue]['card_sales'] = ($byDate[$date][$cardVenue]['card_sales'] ?? 0) + 1;
            $byDate[$date][$cardVenue]['amount'] = ($byDate[$date][$cardVenue]['amount'] ?? 0) + (float) $card->deal_price;
        }
        $redeems = (clone $leadQ)->where('redeem_amount', '>', 0)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('redeemed_at', [$start.' 00:00:00', $end.' 23:59:59'])
                    ->orWhere(fn ($q2) => $q2->whereNull('redeemed_at')->whereBetween('lead_date', [$start, $end]));
            })->get();
        foreach ($redeems as $redeem) {
            $date = $redeem->redeemed_at?->toDateString() ?: (string) $redeem->lead_date;
            $redeemVenue = $redeem->venue ?: '双店';
            $byDate[$date][$redeemVenue]['redeem'] = ($byDate[$date][$redeemVenue]['redeem'] ?? 0) + (float) $redeem->redeem_amount;
        }

        // 随心瑜体验预约是实际排课事实；按日、门店、人员去重后补足 CRM 留资状态统计。
        $bookingQ = applyVenueScope(
            KyBooking::query()->whereBetween('start_at', [$start.' 00:00:00', $end.' 23:59:59']),
            $u, $venue
        );
        $kyByDate = [];
        foreach ($bookingQ->get() as $booking) {
            $date = $booking->start_at?->toDateString();
            if (! $date) {
                continue;
            }
            $identity = $booking->phone ?: ($booking->member_id ?: $booking->source_key);
            // 课型：course_type 2=私教，3=小班（精品课），1=团课（精品团课）
            $courseType = (string) ($booking->raw['course_type'] ?? '');
            $kindKey = match ($courseType) {
                '2' => 'private',
                '3' => 'small',
                default => 'group',
            };
            if (! in_array($booking->status, ['cancelled', 'no_show'], true)) {
                $kyByDate[$date][$booking->venue]['booked'][$identity] = true;
                $kyByDate[$date][$booking->venue]['booked_'.$kindKey][$identity] = true;
            }
            if ($booking->status === 'signed') {
                $session = implode('|', [
                    $booking->venue,
                    $booking->start_at?->format('Y-m-d H:i'),
                    $booking->course_name,
                    $booking->teacher_name,
                    $kindKey,
                ]);
                $kyByDate[$date][$booking->venue]['classes'][$session] = true;
                $kyByDate[$date][$booking->venue]['classes_'.$kindKey][$session] = true;
                if ($booking->is_trial) {
                    $kyByDate[$date][$booking->venue]['experienced'][$identity] = true;
                }
            }
        }
        foreach ($kyByDate as $date => $venues) {
            foreach ($venues as $bookingVenue => $counts) {
                $byDate[$date][$bookingVenue]['booked'] = max(
                    $byDate[$date][$bookingVenue]['booked'] ?? 0,
                    count($counts['booked'] ?? [])
                );
                $byDate[$date][$bookingVenue]['experienced'] = max(
                    $byDate[$date][$bookingVenue]['experienced'] ?? 0,
                    count($counts['experienced'] ?? [])
                );
                $byDate[$date][$bookingVenue]['classes'] = count($counts['classes'] ?? []);
            }
        }
        for ($date = CarbonImmutable::parse($start); $date->lte(CarbonImmutable::parse($end)); $date = $date->addDay()) {
            $byDate[$date->toDateString()] = $byDate[$date->toDateString()] ?? [];
        }

        // 客户到店分布（最近30天有到店记录）
        $custQ = Customer::query();
        if ($u->role !== 'R_SUPER') {
            $custQ->where('venue', $u->venue);
        } elseif ($venue !== '') {
            $custQ->where('venue', $venue);
        }
        $visitQ = (clone $custQ)->where('last_visit', '>=', now()->subDays(30)->toDateString());
        $visit30 = $visitQ->count();
        $activeCustomers = (clone $custQ)->where('attend_m3', '>', 0)->count();
        $memberTotal = (clone $custQ)->where(function ($q) {
            $q->where('layer', '!=', 'P5')->orWhere('external_id', 'like', 'ky:%');
        })->count();

        $sumKey = fn ($k) => collect($byDate)->flatMap(fn ($vs) => collect($vs)->pluck($k))->sum();
        $totalLeads = $sumKey('leads');
        $totalBooked = $sumKey('booked');
        $totalExperienced = $sumKey('experienced');
        $totalDeals = $sumKey('deals');
        $totalCardSales = $sumKey('card_sales');
        $totalClasses = $sumKey('classes');
        $totalAmount = $sumKey('amount');
        $totalRedeem = $sumKey('redeem');

        // 预约/上课班次按私教 / 小班 / 团课拆分
        $privateBooked = 0;
        $smallBooked = 0;
        $groupBooked = 0;
        $privateClasses = 0;
        $smallClasses = 0;
        $groupClasses = 0;
        foreach ($kyByDate as $venues) {
            foreach ($venues as $counts) {
                $privateBooked += count($counts['booked_private'] ?? []);
                $smallBooked += count($counts['booked_small'] ?? []);
                $groupBooked += count($counts['booked_group'] ?? []);
                $privateClasses += count($counts['classes_private'] ?? []);
                $smallClasses += count($counts['classes_small'] ?? []);
                $groupClasses += count($counts['classes_group'] ?? []);
            }
        }

        return [
            'daily' => array_values(collect($byDate)->sortKeys()->map(function ($venues, $date) {
                $out = ['date' => $date];
                foreach ($venues as $v => $c) {
                    $out[$v] = [
                        'leads' => $c['leads'] ?? 0,
                        'booked' => $c['booked'] ?? 0,
                        'experienced' => $c['experienced'] ?? 0,
                        'deals' => $c['deals'] ?? 0,
                        'cardSales' => $c['card_sales'] ?? 0,
                        'classes' => $c['classes'] ?? 0,
                        'amount' => round((float) ($c['amount'] ?? 0), 2),
                        'redeem' => round((float) ($c['redeem'] ?? 0), 2),
                    ];
                }

                return $out;
            })->all()),
            'summary' => [
                'memberTotal' => $memberTotal,
                'leadCount' => $totalLeads,
                'bookingCount' => $totalBooked,
                'visitCount' => $totalExperienced,
                'trialCount' => $totalExperienced,
                'dealCount' => $totalDeals,
                'cardSalesCount' => $totalCardSales,
                'classCount' => $totalClasses,
                'privateBookingCount' => $privateBooked,
                'smallBookingCount' => $smallBooked,
                'groupBookingCount' => $groupBooked,
                'privateClassCount' => $privateClasses,
                'smallClassCount' => $smallClasses,
                'groupClassCount' => $groupClasses,
                'dealAmount' => round((float) $totalAmount, 2),
                'redeemAmount' => round((float) $totalRedeem, 2),
                // 留资登记成交（按成交日 deal_at 落在窗口、含全部来源）：改一笔留资成交即联动，区别于上方按售卡实收的 dealAmount
                'registeredDealCount' => $sales->count(),
                'registeredDealAmount' => round((float) $sales->sum('deal_amount'), 2),
                'dealRate' => $totalExperienced > 0 ? round($totalDeals / $totalExperienced * 100, 1) : 0,
                'leadToVisitRate' => $totalLeads > 0 ? min(100, round($totalExperienced / $totalLeads * 100, 1)) : 0,
                'onlineLeadCount' => $leads->filter(fn ($lead) => isOnlineLead($lead))->count(),
                'onlineDealCount' => $leads->filter(fn ($lead) => isOnlineLead($lead) && $lead->status === '已成交')->count(),
                'onlineDealRate' => ($onlineLeads = $leads->filter(fn ($lead) => isOnlineLead($lead))->count()) > 0
                    ? round($leads->filter(fn ($lead) => isOnlineLead($lead) && $lead->status === '已成交')->count() / $onlineLeads * 100, 1) : 0,
            ],
            'visit30' => $visit30,
            'activeCustomers' => $activeCustomers,
            'attendanceSummary' => [
                'm1' => (clone $custQ)->where('attend_m1', '>', 0)->count(),
                'm2' => (clone $custQ)->where('attend_m2', '>', 0)->count(),
                'm3' => $activeCustomers,
            ],
            'period' => ['start' => $start, 'end' => $end],
        ];
    }

    /** GET /analytics/channels */
    public function channels(Request $r)
    {
        $u = $r->user();
        $venue = (string) $r->query('venue', '');
        abort_unless($venue === '' || in_array($venue, ['绿地店', '东部店'], true), 422, '门店参数无效');
        $start = $r->query('start') ?: now()->startOfMonth()->toDateString();
        $end = $r->query('end') ?: now()->toDateString();

        return ok(Cache::remember(
            self::cacheKey('channels', $u, $venue, $start, $end),
            60,
            fn () => $this->computeChannels($u, $venue, $start, $end)
        ));
    }

    private function computeChannels($u, string $venue, string $start, string $end): array
    {
        $leadQ = applyVenueScope(Lead::query(), $u, $venue);
        $leads = $leadQ->whereBetween('lead_date', [$start, $end])->get();

        $channels = [];
        foreach ($leads as $l) {
            $src = trim((string) $l->source) !== '' ? $l->source : '其他';
            $channels[$src] = ($channels[$src] ?? 0) + 1;
        }
        arsort($channels);

        $rows = [];
        foreach ($channels as $name => $leadsCount) {
            $rows[] = ['channel' => $name, 'leads' => $leadsCount];
        }

        return ['rows' => $rows, 'total' => $leads->count()];
    }

    /** GET /analytics/platforms */
    public function platforms(Request $r)
    {
        $u = $r->user();
        $venue = (string) $r->query('venue', '');
        abort_unless($venue === '' || in_array($venue, ['绿地店', '东部店'], true), 422, '门店参数无效');
        $start = $r->query('start') ?: now()->startOfMonth()->toDateString();
        $end = $r->query('end') ?: now()->toDateString();

        return ok(Cache::remember(
            self::cacheKey('platforms', $u, $venue, $start, $end),
            60,
            fn () => $this->computePlatforms($u, $venue, $start, $end)
        ));
    }

    private function computePlatforms($u, string $venue, string $start, string $end): array
    {
        $leadQ = applyVenueScope(Lead::query(), $u, $venue);
        $leadCohort = (clone $leadQ)->whereBetween('lead_date', [$start, $end])->get();
        $sales = (clone $leadQ)->where('status', '已成交')
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('deal_at', [$start.' 00:00:00', $end.' 23:59:59'])
                    ->orWhere(fn ($q2) => $q2->whereNull('deal_at')->whereBetween('lead_date', [$start, $end]));
            })->get();
        $redeems = (clone $leadQ)->where('redeem_amount', '>', 0)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('redeemed_at', [$start.' 00:00:00', $end.' 23:59:59'])
                    ->orWhere(fn ($q2) => $q2->whereNull('redeemed_at')->whereBetween('lead_date', [$start, $end]));
            })->get();

        $platforms = [];
        foreach ($leadCohort as $l) {
            $platform = trim((string) $l->order_platform) !== '' ? $l->order_platform : (trim((string) $l->source) !== '' ? $l->source : '其他');
            $platforms[$platform] = $platforms[$platform] ?? ['redeem' => 0, 'deal' => 0, 'leads' => 0];
            $platforms[$platform]['leads'] += 1;
        }
        foreach ($sales as $sale) {
            $platform = trim((string) $sale->order_platform) !== '' ? $sale->order_platform : (trim((string) $sale->source) !== '' ? $sale->source : '其他');
            $platforms[$platform] = $platforms[$platform] ?? ['redeem' => 0, 'deal' => 0, 'leads' => 0];
            $platforms[$platform]['deal'] += (float) $sale->deal_amount;
        }
        foreach ($redeems as $redeem) {
            $platform = trim((string) $redeem->order_platform) !== '' ? $redeem->order_platform : (trim((string) $redeem->source) !== '' ? $redeem->source : '其他');
            $platforms[$platform] = $platforms[$platform] ?? ['redeem' => 0, 'deal' => 0, 'leads' => 0];
            $platforms[$platform]['redeem'] += (float) $redeem->redeem_amount;
        }

        $rows = [];
        foreach ($platforms as $name => $v) {
            $rows[] = [
                'platform' => $name,
                'redeem' => round((float) $v['redeem'], 2),
                'deal' => round((float) $v['deal'], 2),
                'leads' => $v['leads'],
            ];
        }
        usort($rows, fn ($a, $b) => $b['deal'] + $b['redeem'] <=> $a['deal'] + $a['redeem']);

        return [
            'rows' => $rows,
            'totalDeal' => round((float) $sales->sum('deal_amount'), 2),
            'totalRedeem' => round((float) $redeems->sum('redeem_amount'), 2),
            'dealCount' => $sales->count(),
        ];
    }
}
