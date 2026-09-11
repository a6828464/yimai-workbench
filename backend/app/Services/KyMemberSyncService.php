<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Customer;
use App\Models\KyBooking;
use App\Models\KyCard;
use App\Models\SyncJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class KyMemberSyncService
{
    private const ACTIVE_CARD_STATUSES = ['4', '5', '7'];

    private const MAX_SYNC_SECONDS = 6600;

    /**
     * @param  array{sync_job?: SyncJob, run_key?: string, display_name?: string, metadata?: array}|SyncJob|null  $artifactContext
     * @param  string|null  $bookingMode  预约拉取模式：null/增量=按上次同步回溯 3 天（默认，定时与手动共用）；full=强制全量拉近两年（手动数据修复用）
     */
    public static function sync(string $venue, string $venueId, SyncJob|array|null $artifactContext = null, ?string $bookingMode = null): array
    {
        $deadline = microtime(true) + self::MAX_SYNC_SECONDS;
        if (! Schema::hasColumns('customers', ['enrolled_at', 'visit_at'])) {
            throw new RuntimeException('数据库结构未升级，请先执行 php artisan migrate --force 后重试');
        }

        // 全量导入需拉取大量预约，放宽内存与执行时间限制
        @ini_set('memory_limit', '512M');
        @set_time_limit(0);

        $artifactWriter = SyncArtifactWriter::from($artifactContext, $venue);
        $snapshotDate = CarbonImmutable::today();

        $members = self::pagedRows('member/api/getmembersbycondwithpager', [
            'cond' => '', 'consultant_id' => -1, 'venue_id' => $venueId,
        ], ['members', 'list'], $deadline);
        if ($members === []) {
            throw new RuntimeException('未读取到会员基础表');
        }
        if ($artifactWriter) {
            $artifactWriter->start('members', '会员基础表', $snapshotDate->toDateString(), $snapshotDate->toDateString(), true);
            $artifactWriter->append('members', $members);
        }

        $cards = self::pagedRows('mcard/api/getmcardsbycond', [
            'cond' => '', 'search' => '', 'consultant_id' => -1, 'venue_id' => $venueId,
        ], ['mcards', 'list'], $deadline);
        if ($cards === []) {
            throw new RuntimeException('未读取到会员卡表');
        }
        if ($artifactWriter) {
            $artifactWriter->start('cards', '会员卡表', $snapshotDate->toDateString(), $snapshotDate->toDateString(), true);
            $artifactWriter->append('cards', $cards);
        }
        // 售卡事实落库：经营看板的售卡张数/金额按售卡时间与实收金额统计。
        self::upsertCardFacts(array_map(fn ($card) => self::cardFact($card, $venue, $venueId), $cards));

        $today = CarbonImmutable::today();
        $month3 = $today->startOfMonth()->subMonth();
        $month2 = $month3->subMonth();
        $month1 = $month2->subMonth();

        // 增量同步：出勤只拉「上次同步之后」的区间（首次无记录则拉近两年）。
        // 五清单只依赖最近三个完整自然月；会员基础表/卡表每次全量（数据量小）。
        $meta = (array) (AppSetting::first()?->sync_meta ?? []);
        $lastSync = isset($meta[$venue]) && $meta[$venue] !== '' ? $meta[$venue] : null;
        $hasBookingFacts = KyBooking::where('venue', $venue)->exists();
        $forceFullBooking = $bookingMode === 'full';
        $rangeStart = ! $forceFullBooking && $lastSync && $hasBookingFacts
            ? CarbonImmutable::parse($lastSync)->subDays(3)
            : $today->subDays(730);
        $isFullBookingSync = $forceFullBooking || ! ($lastSync && $hasBookingFacts);
        if ($artifactWriter) {
            $artifactWriter->setDateRange($rangeStart->toDateString(), $today->toDateString(), $isFullBookingSync);
            $artifactWriter->start(
                'league-bookings', '团课预约表', $rangeStart->toDateString(), $today->toDateString(), $isFullBookingSync
            );
            $artifactWriter->start(
                'private-bookings', '私教预约表', $rangeStart->toDateString(), $today->toDateString(), $isFullBookingSync
            );
        }

        $attendance = [];
        $seenBookings = [];
        $bookingCount = 0;
        $leagueBookingCount = 0;
        $privateBookingCount = 0;
        $bookingFactCount = 0;
        for ($start = $rangeStart; $start->lte($today); $start = $start->addDays(180)) {
            self::ensureBeforeDeadline($deadline);
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
                    self::ensureBeforeDeadline($deadline);
                    $form['page_index'] = $page;
                    $batchRows = self::rows(KyClient::call($path, $form), ['reservations', 'list', 'rows']);
                    $count = count($batchRows);
                    $pageSignature = sha1(json_encode($batchRows, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
                    if ($page > 1 && $pageSignature === $previousPageSignature) {
                        break;
                    }
                    $previousPageSignature = $pageSignature;
                    $bookingCount += $count;
                    $isLeague = str_contains($path, 'league');
                    if ($isLeague) {
                        $leagueBookingCount += $count;
                    } else {
                        $privateBookingCount += $count;
                    }
                    $artifactWriter?->append($isLeague ? 'league-bookings' : 'private-bookings', $batchRows);
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
            // external_id 是全局唯一键，历史错店记录也必须复用，避免重复插入导致整店回滚。
            $existingByExternalId = Customer::query()
                ->whereIn('external_id', array_values(array_filter(array_map(
                    fn ($row) => self::pick($row, ['member_id', 'id', 'home_member_id']) !== ''
                        ? "ky:{$venueId}:".self::pick($row, ['member_id', 'id', 'home_member_id'])
                        : null,
                    $members
                ))))
                ->get()
                ->keyBy('external_id');

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
                    'card_paid_amount' => $cardSummary['card_paid_amount'],
                    'card_stats' => $cardSummary['card_stats'],
                    'cards_list' => $cardSummary['cards_list'],
                ];

                // 生日仅在上游有值时覆盖，避免同步清空工作台人工维护的数据
                if ($birthday = self::pickDate($row, ['birthday', 'born_date', 'birth_date', 'm_birthday', 'member_birthday'])) {
                    $changes['birthday'] = $birthday;
                }

                // 入会时间/到访时间：create_time_format=入会（办理正式会员卡），visit_time_format=到访（访客）。
                // 仅上游有值时覆盖，是新客培养栏目判定「新入会」的锚点。
                if ($enrolledAt = self::pickDate($row, ['create_time_format', 'create_time', 'enroll_time', 'member_time'])) {
                    $changes['enrolled_at'] = $enrolledAt;
                }
                if ($visitAt = self::pickDate($row, ['visit_time_format', 'visit_time', 'first_visit_time'])) {
                    $changes['visit_at'] = $visitAt;
                }

                $customer = $existingByExternalId->get($externalId);
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

        // 记录本次同步时间，供下次增量拉取出勤。
        // 行锁内读改写，防止与手工「今日预约」PUT 并发时互相覆盖丢更新；fetchedAt 按门店记录。
        DB::transaction(function () use ($venue, $todayBookings, $todayTrials, $today, &$meta) {
            $setting = AppSetting::oldest('id')->lockForUpdate()->firstOrCreate([]);
            $meta = (array) ($setting->sync_meta ?? []);
            $meta[$venue] = $today->toDateString();
            $snapshot = (array) ($setting->snapshot ?? []);
            $snapshot['todayBookings'][$venue] = $todayBookings;
            $snapshot['trialBookings'][$venue] = $todayTrials;
            $snapshot['fetchedAt'] = array_merge((array) ($snapshot['fetchedAt'] ?? []), [$venue => now()->format('Y-m-d H:i:s')]);
            $snapshot['fetchedBy'] = 'KeepYoga全量同步';
            $setting->update(['sync_meta' => $meta, 'snapshot' => $snapshot]);
        });

        self::ensureBeforeDeadline($deadline);
        $artifacts = $artifactWriter?->finalize() ?? [];
        // 客户/预约/卡项事实已更新：立即失效五清单与经营看板缓存
        invalidateBusinessCaches();

        return [
            'created' => $created, 'updated' => $updated, 'unchanged' => $unchanged,
            'skipped' => $skipped, 'total' => count($members),
            'cards' => count($cards), 'bookings' => $bookingCount,
            'leagueBookings' => $leagueBookingCount,
            'privateBookings' => $privateBookingCount,
            'signedBookings' => count($seenBookings),
            'bookingFacts' => $bookingFactCount,
            'artifactRunKey' => $artifactWriter?->runKey(),
            'artifacts' => array_map(fn ($artifact) => [
                'id' => $artifact->id,
                'type' => $artifact->artifact_type,
                'displayName' => $artifact->display_name,
                'rowCount' => $artifact->row_count,
                'size' => $artifact->size,
                'sha256' => $artifact->sha256,
            ], $artifacts),
            'attendancePeriod' => [
                'm1' => $month1->format('Y-m'),
                'm2' => $month2->format('Y-m'),
                'm3' => $month3->format('Y-m'),
            ],
        ];
    }

    /** 同步结果 → 任务明细文案（HTTP 导入与定时同步共用同一口径） */
    public static function resultDetail(array $result): string
    {
        return sprintf(
            '已保存快照：会员基础表 %d 条 · 会员卡表 %d 条 · 团课预约 %d 条 · 私教预约 %d 条（出勤口径月 %s / %s / %s）；导入落库：新增 %d · 更新 %d · 未变化 %d · 跳过 %d',
            $result['total'] ?? 0, $result['cards'] ?? 0, $result['leagueBookings'] ?? 0, $result['privateBookings'] ?? 0,
            $result['attendancePeriod']['m1'] ?? '-', $result['attendancePeriod']['m2'] ?? '-', $result['attendancePeriod']['m3'] ?? '-',
            $result['created'] ?? 0, $result['updated'] ?? 0, $result['unchanged'] ?? 0, $result['skipped'] ?? 0
        );
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

        // 全部有效卡汇总（不再只看主卡）：
        // - 次卡剩余节数 = 所有有效次卡(含未开卡)的 residue_amount 合计，一张用完的卡不再污染整体判定
        // - 绑定总量 = 剩余 + 已用(usage_total)，供「剩余占比」阈值
        // - 最早到期日 = 所有有效卡的最早 deadline（次卡/时间卡都可能有到期日）
        // - 时间卡剩余天数/有效期天数合计，供「有效期占比」阈值
        $countResidue = 0.0;
        $countBound = 0.0;
        $hasCountCard = false;
        $daysLeft = 0.0;
        $daysTotal = 0.0;
        $hasTimeCard = false;
        $earliestDeadline = null;
        foreach ($active as $card) {
            $type = (string) ($card['type'] ?? '');
            if ($type === '1' && is_numeric($card['residue_amount'] ?? null)) {
                $hasCountCard = true;
                $residue = max(0.0, self::toNum($card['residue_amount']));
                $countResidue += $residue;
                $countBound += $residue + max(0.0, self::toNum($card['usage_total'] ?? 0));
            }
            if ($type === '2' && is_numeric($card['residue_amount'] ?? null)) {
                $hasTimeCard = true;
                $daysLeft += max(0.0, self::toNum($card['residue_amount']));
                $expiry = self::toNum($card['expiry_days'] ?? 0);
                if ($expiry > 0) {
                    $daysTotal += $expiry;
                }
            }
            $deadline = self::date($card['deadline'] ?? null);
            if ($deadline !== null && ($earliestDeadline === null || $deadline < $earliestDeadline)) {
                $earliestDeadline = $deadline;
            }
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

        $cardPaid = 0.0;
        foreach ($cards as $card) {
            $title = self::pick($card, ['card_title', 'card_name']);
            if (preg_match('/(体验|员工|测试|赠)/u', $title)) {
                continue;
            }
            if ((string) ($card['is_taste'] ?? '0') === '1') {
                continue;
            }
            if ((string) ($card['status_format'] ?? '') === '退卡') {
                continue;
            }
            // 会员卡实收金额 = 非体验/非退卡卡项的 deal_price（成交价）合计
            $paid = self::toNum($card['deal_price'] ?? 0);
            if ($paid > 0) {
                $cardPaid += $paid;
            }
        }

        return [
            'main_card' => $main ? self::pick($main, ['card_title', 'card_name']) : '—',
            // 次卡剩余合计（含未开卡）；无次卡时为 null（时间卡会员不再被误判为 0 节）
            'remain_times' => $hasCountCard ? (int) floor($countResidue) : null,
            // 最早到期日（此前只取主卡到期日）
            'expire_date' => $earliestDeadline,
            'total_purchased' => $totalPurchased,
            'card_paid_amount' => round($cardPaid, 2),
            // 运行时阈值判定依据（阈值可在工作台调整，这里只存原始汇总）
            'card_stats' => [
                'countResidue' => $hasCountCard ? (int) floor($countResidue) : null,
                'countBound' => (int) floor($countBound),
                'daysLeft' => $hasTimeCard ? (int) floor($daysLeft) : null,
                'daysTotal' => $hasTimeCard ? (int) floor($daysTotal) : null,
            ],
            // 有效卡项明细：供会员管理「剩余课时」列逐卡展示（含未开卡标记）
            'cards_list' => array_values(array_map(function (array $card) {
                $type = (string) ($card['type'] ?? '');
                $residue = is_numeric($card['residue_amount'] ?? null)
                    ? max(0.0, self::toNum($card['residue_amount']))
                    : null;

                return [
                    'title' => self::pick($card, ['card_title', 'card_name']),
                    // 1=次卡(节) 2=期限卡(天)
                    'unit' => $type === '2' ? '天' : '节',
                    'residue' => $residue !== null ? (int) floor($residue) : null,
                    'bound' => $type === '1'
                        ? (int) floor(max(0.0, self::toNum($card['residue_amount'] ?? 0)) + max(0.0, self::toNum($card['usage_total'] ?? 0)))
                        : null,
                    'deadline' => self::date($card['deadline'] ?? null),
                    'status' => self::pick($card, ['status_format', 'status']),
                    'unactivated' => (string) ($card['status'] ?? '') === '7',
                ];
            }, $active)),
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
            $changed = self::changedFacts('ky_bookings', $chunk, [
                'member_id', 'member_name', 'phone', 'start_at', 'course_name', 'teacher_name',
                'status_raw', 'status', 'is_trial', 'raw',
            ]);
            KyBooking::upsert($changed, ['source_key'], [
                'member_id', 'member_name', 'phone', 'start_at', 'course_name', 'teacher_name',
                'status_raw', 'status', 'is_trial', 'raw', 'updated_at',
            ]);
        }
    }

    private static function cardFact(array $card, string $venue, string $venueId): array
    {
        $cardId = self::pick($card, ['id', 'card_id', 'card_no']);
        $now = now();

        return [
            'source_key' => "{$venueId}:{$cardId}",
            'venue' => $venue,
            'external_id' => $cardId,
            'card_title' => self::pick($card, ['card_title', 'card_name']),
            'member_id' => self::pick($card, ['member_id', 'm_id']),
            'member_name' => self::pick($card, ['member_name', 'name']),
            'phone' => substr((string) preg_replace('/\D+/', '', self::pick($card, ['phone', 'mobile'])), 0, 20),
            'consultant_name' => self::pick($card, ['consultant_name', 'consultant']),
            'deal_price' => self::toNum($card['deal_price'] ?? 0),
            'price' => self::toNum($card['price'] ?? 0),
            'status' => (string) ($card['status'] ?? ''),
            'status_format' => self::pick($card, ['status_format', 'status_desc']),
            'is_taste' => (string) ($card['is_taste'] ?? '0') === '1',
            'sold_at' => self::date($card['create_time'] ?? null),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private static function upsertCardFacts(array $facts): void
    {
        foreach (array_chunk($facts, 500) as $chunk) {
            $changed = self::changedFacts('ky_cards', $chunk, [
                'venue', 'external_id', 'card_title', 'member_id', 'member_name', 'phone',
                'consultant_name', 'deal_price', 'price', 'status', 'status_format',
                'is_taste', 'sold_at',
            ]);
            KyCard::upsert($changed, ['source_key'], [
                'venue', 'external_id', 'card_title', 'member_id', 'member_name', 'phone',
                'consultant_name', 'deal_price', 'price', 'status', 'status_format',
                'is_taste', 'sold_at', 'updated_at',
            ]);
        }
    }

    private static function changedFacts(string $table, array $facts, array $columns): array
    {
        if ($facts === []) {
            return [];
        }

        $existing = DB::table($table)
            ->whereIn('source_key', array_column($facts, 'source_key'))
            ->get(array_merge(['source_key'], $columns))
            ->keyBy('source_key');

        return array_values(array_filter($facts, function (array $fact) use ($existing, $columns) {
            $stored = $existing->get($fact['source_key']);
            if (! $stored) {
                return true;
            }

            foreach ($columns as $column) {
                $incoming = $fact[$column] ?? null;
                $current = $stored->{$column} ?? null;
                if ($column === 'raw') {
                    if (self::normalizeJson($incoming) !== self::normalizeJson($current)) {
                        return true;
                    }
                } elseif (in_array($column, ['deal_price', 'price'], true)) {
                    if ((float) $incoming !== (float) $current) {
                        return true;
                    }
                } elseif (in_array($column, ['is_trial', 'is_taste'], true)) {
                    if ((bool) $incoming !== (bool) $current) {
                        return true;
                    }
                } elseif ((string) ($incoming ?? '') !== (string) ($current ?? '')) {
                    return true;
                }
            }

            return false;
        }));
    }

    private static function normalizeJson(mixed $json): mixed
    {
        $value = json_decode((string) $json, true);
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($normalize, $item);
        };

        return $normalize($value);
    }

    private static function ensureBeforeDeadline(float $deadline): void
    {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('同步超过 110 分钟安全时限，已停止本次任务，请稍后重试');
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

    private static function pagedRows(string $path, array $form, array $keys, ?float $deadline = null): array
    {
        $rows = [];
        $previousPageSignature = '';
        for ($page = 1; $page <= 100; $page++) {
            if ($deadline !== null) {
                self::ensureBeforeDeadline($deadline);
            }
            $batch = self::rows(KyClient::call($path, $form + [
                'page_index' => $page,
                'page_size' => 5000,
            ]), $keys);
            $pageSignature = sha1(json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            if ($page > 1 && $pageSignature === $previousPageSignature) {
                break;
            }
            $previousPageSignature = $pageSignature;
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

    /** 从原始行多个候选键提取生日，返回 Y-m-d 或空串 */
    private static function pickDate(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (! isset($row[$key]) || $row[$key] === '') {
                continue;
            }
            $parsed = self::date($row[$key]);

            return $parsed ?? '';
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
