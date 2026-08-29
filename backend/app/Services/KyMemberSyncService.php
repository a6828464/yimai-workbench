<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Customer;
use App\Models\KyBooking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class KyMemberSyncService
{
    private const ACTIVE_CARD_STATUSES = ['4', '5', '7'];

    public static function sync(string $venue, string $venueId): array
    {
        // 全量导入需拉取大量预约，放宽内存与执行时间限制
        @ini_set('memory_limit', '512M');
        @set_time_limit(0);

        $members = self::pagedRows('member/api/getmembersbycondwithpager', [
            'cond' => '', 'consultant_id' => -1, 'venue_id' => $venueId,
        ], ['members', 'list']);
        if ($members === []) {
            throw new RuntimeException('未读取到会员基础表');
        }

        $cards = self::pagedRows('mcard/api/getmcardsbycond', [
            'cond' => '', 'search' => '', 'consultant_id' => -1, 'venue_id' => $venueId,
        ], ['mcards', 'list']);
        if ($cards === []) {
            throw new RuntimeException('未读取到会员卡表');
        }

        $today = CarbonImmutable::today();
        $month3 = $today->startOfMonth()->subMonth();
        $month2 = $month3->subMonth();
        $month1 = $month2->subMonth();

        // 增量同步：出勤只拉「上次同步之后」的区间（首次无记录则拉近两年）。
        // 五清单只依赖最近三个完整自然月；会员基础表/卡表每次全量（数据量小）。
        $meta = (array) (AppSetting::first()?->sync_meta ?? []);
        $lastSync = isset($meta[$venue]) && $meta[$venue] !== '' ? $meta[$venue] : null;
        $hasBookingFacts = KyBooking::where('venue', $venue)->exists();
        $rangeStart = $lastSync && $hasBookingFacts
            ? CarbonImmutable::parse($lastSync)->subDays(3)
            : $today->subDays(730);

        $attendance = [];
        $seenBookings = [];
        $bookingCount = 0;
        $leagueBookingCount = 0;
        $privateBookingCount = 0;
        $bookingFactCount = 0;
        for ($start = $rangeStart; $start->lte($today); $start = $start->addDays(180)) {
            $candidateEnd = $start->addDays(179);
            $end = $candidateEnd->lte($today) ? $candidateEnd : $today;
            foreach (['course/api/queryreversionleague', 'course/api/queryreversionprivate'] as $path) {
                $form = [
                    'page_index' => 1, 'page_size' => 5000,
                    's_date' => $start->format('Ymd'), 'e_date' => $end->format('Ymd'),
                    'status_code' => 'all', 'course_id' => 0, 'coach_id' => 0,
                    'm_card_id' => 0, 'search' => '', 'venue_id' => $venueId,
                ];
                if (str_contains($path, 'league')) {
                    $form['course_type'] = 0;
                }
                $previousPageSignature = '';
                for ($page = 1; $page <= 100; $page++) {
                    $form['page_index'] = $page;
                    $batchRows = self::rows(KyClient::call($path, $form), ['reservations', 'list', 'rows']);
                    $count = count($batchRows);
                    $pageSignature = sha1(json_encode($batchRows, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
                    if ($page > 1 && $pageSignature === $previousPageSignature) {
                        break;
                    }
                    $previousPageSignature = $pageSignature;
                    $bookingCount += $count;
                    if (str_contains($path, 'league')) {
                        $leagueBookingCount += $count;
                    } else {
                        $privateBookingCount += $count;
                    }
                    $facts = [];
                    foreach ($batchRows as $row) {
                        self::addAttendance($row, $path, $attendance, $seenBookings, $month1, $month2, $month3);
                        $facts[] = self::bookingFact($row, $path, $venue, $venueId);
                    }
                    self::upsertBookingFacts($facts);
                    $bookingFactCount += count($facts);
                    // Some KeepYoga endpoints ignore page_size and return the complete range.
                    if ($count < 5000 || $count > 5000) {
                        break;
                    }
                }
            }
        }
        // 每次都从预约事实表重算三个完整自然月，避免增量区间把历史出勤覆盖为零。
        $attendance = self::attendanceFromFacts($venue, $month1, $month2, $month3);

        $cardsByMember = [];
        foreach ($cards as $card) {
            $memberId = self::pick($card, ['member_id', 'm_id']);
            if ($memberId !== '') {
                $cardsByMember[$memberId][] = $card;
            }
        }

        $created = $updated = $unchanged = $skipped = 0;
        DB::transaction(function () use (
            $members, $cardsByMember, $attendance, $venue, $venueId,
            &$created, &$updated, &$unchanged, &$skipped
        ) {
            foreach ($members as $row) {
                $memberId = self::pick($row, ['member_id', 'id', 'home_member_id']);
                if ($memberId === '') {
                    $skipped++;

                    continue;
                }

                $externalId = "ky:{$venueId}:{$memberId}";
                $name = self::pick($row, ['name', 'member_name']) ?: '会员';
                $phone = preg_replace('/\D+/', '', self::pick($row, ['phone', 'mobile'])) ?? '';
                $source = self::pick($row, ['source_title', 'source']) ?: 'KeepYoga';
                $consultant = self::pick($row, ['consultant_name', 'consultant', 'adviser_name', 'advisor_name', 'member_consultant']);
                $cardSummary = self::summarizeCards($cardsByMember[$memberId] ?? []);
                $visitSummary = $attendance[$memberId] ?? [];

                $changes = [
                    'name' => $name,
                    'phone' => $phone,
                    'phone_tail' => substr($phone, -4),
                    'venue' => $venue,
                    'source' => $source,
                    'consultant' => $consultant,
                    'main_card' => $cardSummary['main_card'],
                    'remain_times' => $cardSummary['remain_times'],
                    'expire_date' => $cardSummary['expire_date'],
                    'last_visit' => $visitSummary['last_visit'] ?? null,
                    'attend_m1' => $visitSummary['attend_m1'] ?? 0,
                    'attend_m2' => $visitSummary['attend_m2'] ?? 0,
                    'attend_m3' => $visitSummary['attend_m3'] ?? 0,
                    'total_purchased' => $cardSummary['total_purchased'],
                ];

                $customer = Customer::where('external_id', $externalId)->first();
                if (! $customer) {
                    Customer::create($changes + [
                        'layer' => 'P4', 'status' => '待完善', 'owner' => $consultant ?: '未分配',
                        'next_action' => '分配负责人并完善会员档案', 'external_id' => $externalId,
                    ]);
                    $created++;

                    continue;
                }

                // 增量同步：本次区间内未到访的会员，保留其历史 last_visit，避免被误判为待复活
                if (empty($changes['last_visit']) && $customer->last_visit) {
                    $changes['last_visit'] = $customer->last_visit;
                }

                if ($customer->layer === 'P5' && $customer->main_card === '待同步卡项') {
                    $changes += ['layer' => 'P4', 'status' => '待完善', 'owner' => $consultant ?: '未分配', 'next_action' => '分配负责人并完善会员档案'];
                }
                $customer->fill($changes);
                if ($customer->isDirty()) {
                    $customer->save();
                    $updated++;
                } else {
                    $unchanged++;
                }
            }
        });

        $todayBookings = KyBooking::query()
            ->where('venue', $venue)
            ->whereBetween('start_at', [$today->startOfDay(), $today->endOfDay()])
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->count();
        $todayTrials = KyBooking::query()
            ->where('venue', $venue)
            ->whereBetween('start_at', [$today->startOfDay(), $today->endOfDay()])
            ->where('is_trial', true)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->count();

        // 记录本次同步时间，供下次增量拉取出勤
        $setting = AppSetting::firstOrCreate([]);
        $meta = (array) ($setting->sync_meta ?? []);
        $meta[$venue] = $today->toDateString();
        $snapshot = (array) ($setting->snapshot ?? []);
        $snapshot['todayBookings'][$venue] = $todayBookings;
        $snapshot['trialBookings'][$venue] = $todayTrials;
        $snapshot['fetchedAt'] = now()->format('Y-m-d H:i:s');
        $snapshot['fetchedBy'] = 'KeepYoga全量同步';
        $setting->update(['sync_meta' => $meta, 'snapshot' => $snapshot]);

        return [
            'created' => $created, 'updated' => $updated, 'unchanged' => $unchanged,
            'skipped' => $skipped, 'total' => count($members),
            'cards' => count($cards), 'bookings' => $bookingCount,
            'leagueBookings' => $leagueBookingCount,
            'privateBookings' => $privateBookingCount,
            'signedBookings' => count($seenBookings),
            'bookingFacts' => $bookingFactCount,
            'attendancePeriod' => [
                'm1' => $month1->format('Y-m'),
                'm2' => $month2->format('Y-m'),
                'm3' => $month3->format('Y-m'),
            ],
        ];
    }

    private static function summarizeCards(array $cards): array
    {
        $active = array_values(array_filter($cards, function (array $card) {
            if (! in_array((string) ($card['status'] ?? ''), self::ACTIVE_CARD_STATUSES, true)) {
                return false;
            }
            if ((string) ($card['is_taste'] ?? '0') === '1') {
                return false;
            }
            $title = self::pick($card, ['card_title', 'card_name']);

            return ! preg_match('/(体验|员工|测试)/u', $title);
        }));

        usort($active, function (array $a, array $b) {
            $statusPriority = ['5' => 3, '4' => 2, '7' => 1];
            $aHasBalance = (string) ($a['type'] ?? '') !== '1' || (float) ($a['residue_amount'] ?? 0) > 0;
            $bHasBalance = (string) ($b['type'] ?? '') !== '1' || (float) ($b['residue_amount'] ?? 0) > 0;
            if ($aHasBalance !== $bHasBalance) {
                return $bHasBalance <=> $aHasBalance;
            }
            $statusDiff = ($statusPriority[(string) ($b['status'] ?? '')] ?? 0)
                <=> ($statusPriority[(string) ($a['status'] ?? '')] ?? 0);
            if ($statusDiff !== 0) {
                return $statusDiff;
            }

            return (int) ($b['deadline'] ?? 0) <=> (int) ($a['deadline'] ?? 0);
        });

        $main = $active[0] ?? null;
        $remain = null;
        if ($main && (string) ($main['type'] ?? '') === '1' && is_numeric($main['residue_amount'] ?? null)) {
            $remain = max(0, (int) floor((float) $main['residue_amount']));
        }

        $totalPurchased = 0;
        foreach ($cards as $card) {
            $title = self::pick($card, ['card_title', 'card_name']);
            if (! str_contains($title, '私教') || preg_match('/(体验|员工|测试|赠)/u', $title)) {
                continue;
            }
            if ((string) ($card['status'] ?? '') === '29' || (string) ($card['is_taste'] ?? '0') === '1') {
                continue;
            }
            // 累计购买私教课量 = 该次卡当前绑定节数(剩余) + 已用节数。
            // 注意：initial_amount 为「N次」字符串且含义为赠送次数，不可作为累计购买口径。
            if ((string) ($card['type'] ?? '') === '1') {
                $bound = self::toNum($card['residue_amount'] ?? 0) + self::toNum($card['usage_total'] ?? 0);
                if ($bound > 0) {
                    $totalPurchased += (int) floor($bound);
                }
            }
        }

        return [
            'main_card' => $main ? self::pick($main, ['card_title', 'card_name']) : '—',
            'remain_times' => $remain,
            'expire_date' => $main ? self::date($main['deadline'] ?? null) : null,
            'total_purchased' => $totalPurchased,
        ];
    }

    /** 提取字段中的首个数值（兼容 "110"、"0.00"、"36节" 等格式） */
    private static function toNum(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        $m = [];
        preg_match('/-?\d+(\.\d+)?/', (string) $value, $m);

        return isset($m[0]) ? (float) $m[0] : 0.0;
    }

    private static function addAttendance(
        array $booking,
        string $path,
        array &$attendance,
        array &$seenBookings,
        CarbonImmutable $month1,
        CarbonImmutable $month2,
        CarbonImmutable $month3
    ): void {
        if ((string) ($booking['status_desc'] ?? '') !== '已签到') {
            return;
        }
        $memberId = self::pick($booking, ['m_id', 'member_id']);
        $visitedAt = self::date($booking['start_time'] ?? $booking['course_date'] ?? null);
        if ($memberId === '' || ! $visitedAt) {
            return;
        }
        $recordId = self::pick($booking, ['id', 'reservation_id']);
        $dedupeKey = $path.':'.$recordId.':'.$memberId;
        if (isset($seenBookings[$dedupeKey])) {
            return;
        }
        $seenBookings[$dedupeKey] = true;

        $date = CarbonImmutable::parse($visitedAt);
        $attendance[$memberId]['last_visit'] = max(
            $attendance[$memberId]['last_visit'] ?? '0000-00-00',
            $date->toDateString()
        );
        foreach ([$month1, $month2, $month3] as $index => $month) {
            if ($date->betweenIncluded($month->startOfMonth(), $month->endOfMonth())) {
                $key = 'attend_m'.($index + 1);
                $attendance[$memberId][$key] = ($attendance[$memberId][$key] ?? 0) + 1;
            }
        }
    }

    private static function attendanceFromFacts(
        string $venue,
        CarbonImmutable $month1,
        CarbonImmutable $month2,
        CarbonImmutable $month3
    ): array {
        $attendance = [];
        KyBooking::query()
            ->where('venue', $venue)
            ->where('status', 'signed')
            ->whereNotNull('member_id')
            ->where('member_id', '!=', '')
            ->where('start_at', '>=', $month1->startOfMonth())
            ->orderBy('id')
            ->chunkById(1000, function ($bookings) use (&$attendance, $month1, $month2, $month3) {
                foreach ($bookings as $booking) {
                    $memberId = (string) $booking->member_id;
                    $date = CarbonImmutable::parse($booking->start_at);
                    $attendance[$memberId]['last_visit'] = max(
                        $attendance[$memberId]['last_visit'] ?? '0000-00-00',
                        $date->toDateString()
                    );
                    foreach ([$month1, $month2, $month3] as $index => $month) {
                        if ($date->betweenIncluded($month->startOfMonth(), $month->endOfMonth())) {
                            $key = 'attend_m'.($index + 1);
                            $attendance[$memberId][$key] = ($attendance[$memberId][$key] ?? 0) + 1;
                        }
                    }
                }
            });

        return $attendance;
    }

    private static function bookingFact(array $row, string $path, string $venue, string $venueId): array
    {
        $type = str_contains($path, 'league') ? '团课' : '私教';
        $memberId = self::pick($row, ['m_id', 'member_id']);
        $memberName = self::pick($row, ['m_name', 'member_name', 'name']);
        $phone = preg_replace('/\D+/', '', self::pick($row, ['phone', 'mobile', 'member_phone'])) ?? '';
        $startAt = self::dateTime($row['start_time'] ?? $row['course_date'] ?? null);
        $courseName = self::pick($row, ['course_name', 'course_title', 'course']);
        $teacherName = self::pick($row, ['coach_name', 'teacher_name', 'coach', 'teacher']);
        $statusRaw = self::pick($row, ['status_desc', 'status_name', 'status']);
        $trialText = implode(' ', array_map(fn ($key) => (string) ($row[$key] ?? ''), [
            'm_name', 'member_name', 'course_name', 'course_title', 'card_title', 'card_name', 'remark',
        ]));
        $recordId = self::pick($row, ['id', 'reservation_id']);
        $identity = $recordId !== '' ? $recordId : sha1(implode('|', [$memberId, $memberName, $startAt, $courseName]));
        $now = now();

        return [
            'source_key' => "{$venueId}:{$type}:{$identity}",
            'venue' => $venue,
            'booking_type' => $type,
            'member_id' => $memberId,
            'member_name' => $memberName,
            'phone' => substr($phone, 0, 20),
            'start_at' => $startAt,
            'course_name' => $courseName,
            'teacher_name' => $teacherName,
            'status_raw' => $statusRaw,
            'status' => self::bookingStatus($statusRaw),
            'is_trial' => str_contains($trialText, '体验'),
            'raw' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private static function upsertBookingFacts(array $facts): void
    {
        foreach (array_chunk($facts, 500) as $chunk) {
            KyBooking::upsert($chunk, ['source_key'], [
                'member_id', 'member_name', 'phone', 'start_at', 'course_name', 'teacher_name',
                'status_raw', 'status', 'is_trial', 'raw', 'updated_at',
            ]);
        }
    }

    private static function bookingStatus(string $status): string
    {
        if (preg_match('/已签到|签到|已完成/u', $status)) {
            return 'signed';
        }
        if (preg_match('/取消|作废/u', $status)) {
            return 'cancelled';
        }
        if (preg_match('/爽约|未到|旷课/u', $status)) {
            return 'no_show';
        }
        if (preg_match('/预约|待上课/u', $status)) {
            return 'booked';
        }

        return 'unknown';
    }

    private static function rows(array $response, array $keys): array
    {
        $data = $response['data'] ?? [];
        if (array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }
        if (! is_array($data)) {
            return [];
        }
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return array_values(array_filter($data[$key], 'is_array'));
            }
        }

        return [];
    }

    private static function pagedRows(string $path, array $form, array $keys): array
    {
        $rows = [];
        for ($page = 1; $page <= 100; $page++) {
            $batch = self::rows(KyClient::call($path, $form + [
                'page_index' => $page,
                'page_size' => 5000,
            ]), $keys);
            $rows = array_merge($rows, $batch);
            if (count($batch) < 5000) {
                break;
            }
        }

        return $rows;
    }

    private static function pick(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && $row[$key] !== '') {
                return trim((string) $row[$key]);
            }
        }

        return '';
    }

    private static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
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
            $text = (string) (int) $number;
            if (strlen($text) === 8) {
                return CarbonImmutable::createFromFormat('Ymd', $text)->toDateString();
            }
        }
        try {
            return CarbonImmutable::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function dateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            if (is_numeric($value)) {
                $number = (int) $value;
                if ($number > 100000000000) {
                    $number = (int) floor($number / 1000);
                }
                if ($number > 1000000000) {
                    return CarbonImmutable::createFromTimestamp($number)->format('Y-m-d H:i:s');
                }
                $text = (string) $number;
                if (strlen($text) === 8) {
                    return CarbonImmutable::createFromFormat('Ymd', $text)->startOfDay()->format('Y-m-d H:i:s');
                }
            }

            return CarbonImmutable::parse((string) $value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
