<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Customer;
use App\Models\KyBooking;
use App\Models\KyCard;
use App\Models\SyncJob;
use App\Support\CardLifecycle;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class KyMemberSyncService
{
    /**
     * 卡项分类口径（v3.9）。
     *
     * 旧实现在这里维护 `ACTIVE_CARD_STATUSES = ['4','5','7']` 白名单，把「是否在用」
     * 判成「上游状态码是否在白名单里」——那是用户症状①（旧年卡已过期仍进清单）与
     * 「全过期会员落 P5」的共同根因：白名单既不读 deadline，也不表达历史。
     *
     * v3.9 起**唯一入口是 `CardLifecycle::state()`**：
     *   current（4/5，且 deadline ≥ 今天）/ unactivated（7）/ history（6/29/39 或 deadline < 今天）。
     * 过期实例不写入 `cards_list`（判定输入），而写入 `card_stats.historyCards`
     * + 兼容区 `card_stats.expiredCards`（仅炸弹会员消费）。
     *
     * ⚠️ 不要再在这里恢复任何 status 白名单：新增状态只需改 `CardLifecycle::stateOf()`。
     */
    private const MAX_SYNC_SECONDS = 6600;

    /** 判定口径配置（只读；表不存在时回落空数组，保证命令/测试环境可用） */
    private static function currentRules(): array
    {
        try {
            return rules();
        } catch (\Throwable) {
            return [];
        }
    }

    /** 上游状态码（numeric status → 中文映射），仅用于 29/39 这类统计排除 */
    private static function cardStatusCode(array $card): ?string
    {
        $raw = $card['status'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_numeric($raw)) {
            return (string) (int) $raw;
        }

        return CardLifecycle::STATUS_CODES[(string) $raw] ?? null;
    }

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

        // 姓名 → 账号 id 的全表映射，一次取好。
        // 归属列存的是姓名（会变、会撞），id 才是长期依据；批量写时逐行查库太慢，
        // 所以在这里取一次、下列循环里用数组查。
        $staffIds = staffNameToIdMap();

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
        $windows = self::attendanceWindows($today);

        // 增量同步：出勤只拉「上次同步之后」的区间（首次无记录则拉近两年）。
        // 五清单只依赖最近 90 天；会员基础表/卡表每次全量（数据量小）。
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
                        self::trackSignedBooking($row, $path, $seenBookings);
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
        // 每次都从预约事实表重算三个 30 天窗口，避免增量区间把历史出勤覆盖为零。
        $attendance = self::attendanceFromFacts($venue, $windows);

        $cardsByMember = [];
        foreach ($cards as $card) {
            $memberId = self::pick($card, ['member_id', 'm_id']);
            if ($memberId !== '') {
                $cardsByMember[$memberId][] = $card;
            }
        }

        $created = $updated = $unchanged = $skipped = 0;
        // 配置只读一次：summarizeCards 需要 tasteTitlePattern 与过期余额窗，
        // 若在每个会员里各读一次 AppSetting，一次全量同步会多出 1500+ 次查询。
        $syncRules = self::currentRules();
        DB::transaction(function () use (
            $members, $cardsByMember, $attendance, $venue, $venueId, $syncRules,
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
                $cardSummary = self::summarizeCards($cardsByMember[$memberId] ?? [], $syncRules);
                $visitSummary = $attendance[$memberId] ?? [];

                $changes = [
                    'name' => $name,
                    'phone' => $phone,
                    'phone_tail' => substr($phone, -4),
                    'venue' => $venue,
                    'source' => $source,
                    'consultant' => $consultant,
                    'consultant_user_id' => $staffIds[$consultant] ?? null,
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
                        // 占位分层：新行尚未重算分层，先给 P5（=「无资产」的等价分层）。
                        // 紧接着由 recalculateMemberLayers() 按真实卡项写入 P0-P4，
                        // 所以这里绝不能写死 P4 —— 那正是「所有同步会员恒为 P4」的成因。
                        'layer' => 'P5', 'status' => '待完善', 'owner' => $consultant ?: '未分配',
                        'owner_user_id' => $staffIds[$consultant] ?? null,
                        'next_action' => '分配负责人并完善会员档案', 'external_id' => $externalId,
                    ]);
                    $created++;

                    continue;
                }

                // 增量同步：本次区间内未到访的会员，保留其历史 last_visit，避免被误判为待复活
                if (empty($changes['last_visit']) && $customer->last_visit) {
                    $changes['last_visit'] = $customer->last_visit;
                }

                // 「待同步卡项」是首次建档的占位主卡，本次同步已拿到真实卡项 → 解除占位。
                // 分层不再在这里写死，交给 recalculateMemberLayers() 统一算。
                if ($customer->main_card === '待同步卡项') {
                    $changes += [
                        'status' => '待完善', 'owner' => $consultant ?: '未分配',
                        'owner_user_id' => $staffIds[$consultant] ?? null,
                        'next_action' => '分配负责人并完善会员档案',
                    ];
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
        // 分层是**派生数据**：卡项/出勤刚被本次同步改写，P0-P4 必须跟着重算，
        // 否则「经营池分层恒为空」的缺陷会在每次同步后复现。
        // 它绝不能反过来把一次成功的同步拖失败——所以单独兜住异常，只记日志。
        $layersChanged = 0;
        try {
            $layersChanged = recalculateMemberLayers();
        } catch (Throwable $e) {
            Log::warning('同步后重算经营分层失败', ['venue' => $venue, 'error' => $e->getMessage()]);
        }
        // 分层写的是 layer 列（不经 Eloquent，不动 updated_at），但清单缓存里的
        // 「经营分层」明细已变，再失效一次让下次读取立刻反映新分层。
        if ($layersChanged > 0) {
            invalidateBusinessCaches('member_lists');
        }

        return [
            'created' => $created, 'updated' => $updated, 'unchanged' => $unchanged,
            'skipped' => $skipped, 'total' => count($members),
            'layersChanged' => $layersChanged,
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
            // 出勤口径：三个连续 30 天滚动窗口（不再按自然月）
            'attendancePeriod' => [
                'm1' => '再前30天',
                'm2' => '前30天',
                'm3' => '近30天',
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

    /**
     * 会员卡项 → cards_list / historyCards / entitlement / card_stats（v3.9 契约）。
     *
     * @param  array|null  $rules  同步主流程传入一次（1580 个会员只读一次配置）；测试可省略
     */
    private static function summarizeCards(array $cards, ?array $rules = null): array
    {
        // ── v3.9：判定单元从「会员级汇总」改为「卡项实例」──────────────────────
        //
        // 分类完全交给 CardLifecycle::normalize()（同步/判定唯一共享口径），这里只做：
        // ①同 id 去重（跨馆镜像行）②按 state 分桶 ③按契约写 cards_list / historyCards
        // ④按单位分组汇总（节/天/元 永不混算）。
        //
        // 旧实现的三个根因在这里被结构性消除：
        //  - `ACTIVE_CARD_STATUSES = ['4','5','7']` 白名单 → 换成 state()（deadline<TODAY 即 history）；
        //  - main_card 的 `(hasBalance, statusPriority, deadline desc)` → 换成 sortKey()（当前优先）；
        //  - expire_date = min(所有可用卡 deadline) → 换成「当前实例的最早 deadline」。
        $syncRules = $rules ?? self::currentRules();
        $pattern = CardLifecycle::tastePattern($syncRules);

        // ① 去重：同一 id 因跨馆共享卡被导出两次（can_op=1 本馆 / can_op=0 镜像）。
        //    保留 can_op=1 的那行；can_op 缺失时后出现的覆盖先出现的。
        $deduped = [];
        $indexById = [];
        $mirrorDeduped = false;
        foreach ($cards as $row) {
            $id = self::pick($row, ['id']);
            if ($id === '') {
                $deduped[] = $row;

                continue;
            }
            if (isset($indexById[$id])) {
                $mirrorDeduped = true;
                $kept = $deduped[$indexById[$id]];
                $keptCanOp = self::pick($kept, ['can_op', 'canOp']);
                $rowCanOp = self::pick($row, ['can_op', 'canOp']);
                if ($rowCanOp === '1' && $keptCanOp !== '1') {
                    $deduped[$indexById[$id]] = $row;
                } elseif ($rowCanOp === $keptCanOp) {
                    $deduped[$indexById[$id]] = $row;
                }

                continue;
            }
            $indexById[$id] = count($deduped);
            $deduped[] = $row;
        }

        // ② 归一化 + 分桶（excluded 不进任何判定集合，只作「曾经有卡」的证据）
        $current = $unactivated = $history = $excluded = [];
        foreach ($deduped as $row) {
            $instance = CardLifecycle::normalize($row, $pattern);
            // 保留原始行：已耗次数的权威推导需要 curr_unit_cash_value / usage_total /
            // consume_amount_format 三个上游字段，而归一化结果只保留判定语义字段。
            $instance['raw'] = $row;
            match ($instance['state']) {
                'current' => $current[] = $instance,
                'unactivated' => $unactivated[] = $instance,
                'history' => $history[] = $instance,
                default => $excluded[] = $instance,
            };
        }

        // ── 汇总（唯一口径见规范 §2.6；单位按 节/天/元 分组，永不混算）──────────
        //
        // 口径要点：
        // - countResidue / countBound 是**展示口径**（含未开卡的库存），判定层不得用它替代
        //   entitlement.usableCount（D12：两个「合计」不同源）；
        // - daysLeft 改为**按 deadline 累加**（旧实现取 residue_amount 恒 0，F1 的第二根因）；
        // - storedValue 独立统计（元），既不进 countResidue 也不进 daysLeft（D05/I5）；
        // - historySections / historyAmount 只统计历史实例的沉睡余额（EXPIRED_BAL 另受窗口约束）。
        $countResidue = 0.0;
        $countBound = 0.0;
        $underivable = 0;
        $hasCountCard = false;
        $daysLeft = 0.0;
        $daysTotal = 0.0;
        $hasTimeCard = false;
        $storedValue = 0.0;
        $historySections = 0.0;
        $historyAmount = 0.0;
        $balanceSections = 0.0;
        $balanceAmount = 0.0;
        $balanceCards = 0;
        $balanceWindow = CardLifecycle::expiredBalanceWindow($syncRules);

        foreach ($current as &$card) {
            if ($card['type'] === CardLifecycle::TYPE_COUNT) {
                $hasCountCard = true;
                if ($card['remaining'] !== null) {
                    $countResidue += $card['remaining'];
                    if ($card['remaining'] > 0) {
                        // 分母 = 剩余节数 + **已耗次数**（不是已耗金额！单位错曾造出 29/495.63=5.9% 的假尾段）
                        $consumed = self::consumedCount($card['raw']);
                        if ($consumed === null) {
                            // 推导不出已耗次数：不猜。分母只含剩余节数 → 占比偏高（少报尾段），
                            // 绝不会凭空造出「分母被金额撑大 ⇒ 假装尾段」的假命中。
                            $underivable++;
                            $card['bound'] = $card['remaining'];
                        } else {
                            $card['bound'] = $card['remaining'] + $consumed;
                        }
                        $countBound += $card['bound'];
                    } else {
                        $card['bound'] = $card['remaining'];
                    }
                }
            }
            if ($card['type'] === CardLifecycle::TYPE_TIME) {
                $hasTimeCard = true;
                if ($card['remaining'] !== null) {
                    $daysLeft += $card['remaining'];
                }
                if ($card['validDays'] !== null) {
                    $daysTotal += $card['validDays'];
                }
            }
            if ($card['type'] === CardLifecycle::TYPE_STORED && $card['remaining'] !== null) {
                $storedValue += $card['remaining'];
            }
        }
        unset($card);

        foreach ($unactivated as &$card) {
            if ($card['type'] === CardLifecycle::TYPE_COUNT) {
                $hasCountCard = true;
                if ($card['remaining'] !== null) {
                    $countResidue += $card['remaining'];
                    if ($card['remaining'] > 0) {
                        $consumed = self::consumedCount($card['raw'] ?? []);
                        $card['bound'] = $consumed === null ? $card['remaining'] : $card['remaining'] + $consumed;
                        if ($consumed === null) {
                            $underivable++;
                        }
                        $countBound += $card['bound'];
                    } else {
                        $card['bound'] = $card['remaining'];
                    }
                }
            }
            if ($card['type'] === CardLifecycle::TYPE_STORED && $card['remaining'] !== null) {
                $storedValue += $card['remaining'];
            }
        }
        unset($card);

        foreach ($history as $card) {
            $residual = CardLifecycle::residualBalance($card);
            if ($card['type'] === CardLifecycle::TYPE_COUNT) {
                $historySections += $residual;
            }
            if ($card['type'] === CardLifecycle::TYPE_STORED) {
                $historyAmount += $residual;
            }
            // 过期有余额：只认 expired / expired_by_clock（退卡/停卡永不参与，D10），
            // 且必须有过期日 + 落在展示窗内（§3.4 四条边界）。
            if (in_array($card['historyReason'], ['expired', 'expired_by_clock'], true)
                && $residual > 0
                && CardLifecycle::withinExpiredWindow($card, $balanceWindow)) {
                $balanceCards++;
                if ($card['type'] === CardLifecycle::TYPE_COUNT) {
                    $balanceSections += $residual;
                }
                if ($card['type'] === CardLifecycle::TYPE_STORED) {
                    $balanceAmount += $residual;
                }
            }
        }

        // ── 累计购买私教课量 / 实收金额（S16：退卡 29 与停卡 39 都跳过）──────────
        //
        // 旧实现只跳过 29（退卡），停卡（39）仍计入 total_purchased / card_paid_amount，
        // 于是「冻结中的权益」被算成已成交业绩。本任务只动这两个统计字段（最小改动）。
        $totalPurchased = 0;
        foreach ($deduped as $card) {
            $title = self::pick($card, ['card_title', 'card_name']);
            if (! str_contains($title, '私教') || preg_match('/(体验|员工|测试|赠)/u', $title)) {
                continue;
            }
            if (in_array(self::cardStatusCode($card), ['29', '39'], true)
                || (string) ($card['is_taste'] ?? '0') === '1') {
                continue;
            }
            // 累计购买私教课量 = 该次卡当前绑定节数(剩余) + 已耗**次数**。
            // 注意：initial_amount 为「N次」字符串且含义为赠送次数，不可作为累计购买口径。
            // ⚠️ 这里此前误用 usage_total（金额），与 countBound 是同一个量级错误：
            // 一张 30 节卡会被算成「累计购买 495 节」。
            if ((string) ($card['type'] ?? '') === '1') {
                $consumed = self::consumedCount($card);
                $bound = self::toNum($card['residue_amount'] ?? 0) + ($consumed ?? 0);
                if ($bound > 0) {
                    $totalPurchased += (int) floor($bound);
                }
            }
        }

        $cardPaid = 0.0;
        foreach ($deduped as $card) {
            $title = self::pick($card, ['card_title', 'card_name']);
            if (preg_match('/(体验|员工|测试|赠)/u', $title)) {
                continue;
            }
            if ((string) ($card['is_taste'] ?? '0') === '1') {
                continue;
            }
            if (in_array(self::cardStatusCode($card), ['29', '39'], true)
                || in_array((string) ($card['status_format'] ?? ''), ['退卡', '停卡'], true)) {
                continue;
            }
            // 会员卡实收金额 = 非体验/非退卡/非停卡卡项的 deal_price（成交价）合计
            $paid = self::toNum($card['deal_price'] ?? 0);
            if ($paid > 0) {
                $cardPaid += $paid;
            }
        }

        // ③ 系列归因 + 汇总（§2.6）：主卡/到期日只由当前实例决定，历史永不拉早。
        //
        // A-01：系列序号直接挂在实例上（annotateSeries），不再用 identity 当 map 键 ——
        // 缺 id/card_no 时 identity 是 hash，同字段多卡会撞键。标注后按 state 重新分桶，
        // 保证 cards_list / historyCards / entitlement 用的是同一份带序号快照。
        $allInstances = CardLifecycle::annotateSeries(array_merge($current, $unactivated, $history));
        $current = array_values(array_filter($allInstances, fn (array $i) => $i['state'] === 'current'));
        $unactivated = array_values(array_filter($allInstances, fn (array $i) => $i['state'] === 'unactivated'));
        $history = array_values(array_filter($allInstances, fn (array $i) => $i['state'] === 'history'));
        [$mainInstance, $mainCardState] = CardLifecycle::mainCardFrom($allInstances);
        [$expireDate, $expireDateState] = CardLifecycle::expireDateFrom($allInstances);
        $nextExpireDays = CardLifecycle::daysUntilDate($expireDate);

        $usableCount = 0.0;
        foreach ($current as $card) {
            if ($card['type'] === CardLifecycle::TYPE_COUNT && CardLifecycle::isUsable($card)) {
                $usableCount += $card['remaining'];
            }
        }

        // ① 历史实例明细（唯一历史事实源，全类型，带身份）
        $historyCards = array_values(array_map(function (array $card) {
            return [
                'id' => $card['id'],
                'cardNo' => $card['cardNo'],
                'cardId' => $card['cardId'],
                'title' => $card['title'],
                'type' => (string) $card['type'],
                'unit' => $card['unit'],
                // 期限卡到期即失效 → 残余恒 0（定义，不是缺失）；次卡/储值卡保留原始残余。
                'residue' => $card['type'] === CardLifecycle::TYPE_TIME
                    ? 0
                    : ($card['remaining'] === null ? null : $card['remaining']),
                'deadline' => $card['deadline'],
                'daysUntil' => $card['daysUntil'],
                'statusCode' => $card['statusCode'],
                'status' => $card['statusLabel'] ?? (CardLifecycle::STATUS_LABELS[$card['statusCode'] ?? ''] ?? ''),
                'state' => 'history',
                'historyReason' => $card['historyReason'],
                'createTime' => $card['createTime'],
                'soldAt' => $card['createTime'],
                'activatedAt' => $card['activateTime'],
                'expired' => true,
                'daysLeft' => $card['daysUntil'],
                'valueType' => match ($card['type']) {
                    CardLifecycle::TYPE_TIME => 'time',
                    CardLifecycle::TYPE_STORED => 'stored',
                    default => 'count',
                },
                'isTaste' => false,
                // 同步侧已判定的降级码（判定层无法从纠正后的字段复现这些事实）
                'degraded' => $card['degraded'],
            ];
        }, $history));

        // ② 过期卡保留区（兼容区，C8 收窄）：type=1 ∧ 已过期 ∧ 有余额 ∧ 排除 29/39 ∧ 非体验。
        //    仅供「炸弹会员」消费；historyCards 才是唯一历史事实源。
        $expiredCards = array_values(array_map(function (array $card) {
            return [
                'id' => $card['id'],
                'cardNo' => $card['cardNo'],
                'title' => $card['title'],
                'residue' => (int) floor($card['remaining']),
                'deadline' => $card['deadline'],
                'status' => (string) $card['statusCode'],
                'statusCode' => $card['statusCode'],
                'daysExpired' => $card['daysUntil'] === null ? null : abs($card['daysUntil']),
                'type' => '1',
            ];
        }, array_values(array_filter($history, function (array $card) {
            return $card['type'] === CardLifecycle::TYPE_COUNT
                && ! in_array($card['statusCode'], ['29', '39'], true)
                && ! $card['isTaste']
                && $card['deadline'] !== null
                && $card['daysUntil'] !== null
                && $card['daysUntil'] < 0
                && ($card['remaining'] ?? 0) > 0;
        }))));

        // ③ 被排除的体验/赠品卡：不进任何判定集合，但保留为「曾经有卡」的证据（A11）。
        $excludedCards = array_values(array_map(fn (array $card) => [
            'title' => $card['title'],
            'type' => (string) $card['type'],
            'isTaste' => true,
            'tasteReason' => $card['tasteReason'],
        ], $excluded));

        $syncDegraded = [];
        if ($mirrorDeduped) {
            $syncDegraded[] = 'mirror_row_deduped';
        }
        if ($excluded !== []) {
            $syncDegraded[] = 'taste_excluded_by_pattern';
        }

        return [
            'main_card' => $mainInstance !== null ? $mainInstance['title'] : '—',
            // 次卡剩余合计（含未开卡）；无次卡时为 null（时间卡会员不再被误判为 0 节）
            'remain_times' => $hasCountCard ? (int) floor($countResidue) : null,
            // 最早到期日：**当前实例**的最早 deadline；无当前实例才回退最近历史（带 state 标注）
            'expire_date' => $expireDate,
            'total_purchased' => $totalPurchased,
            'card_paid_amount' => round($cardPaid, 2),
            // 运行时阈值判定依据 + v3.9 新契约（§1.4）
            'card_stats' => [
                'countResidue' => $hasCountCard ? (int) floor($countResidue) : null,
                'countBound' => (int) floor($countBound),
                // 期限卡剩余天数改为按 deadline 累加（旧实现取 residue_amount 恒 0，F1 第二根因）
                'daysLeft' => $hasTimeCard ? (int) floor($daysLeft) : null,
                'daysTotal' => $hasTimeCard ? (int) floor($daysTotal) : null,
                // 已耗次数推导不出的在用次卡张数：>0 时判定层必须显式降级告知，
                // 不得让「分母只剩剩余节数」这件事静默发生（见 helpers 的 degraded）。
                'countBoundUnderivable' => $underivable,
                // 过期卡保留区（兼容）：只保留**算得出沉睡量**的次卡；29/39 已排除（S11）。
                'expiredCards' => $expiredCards,
                // 被排除的体验/赠品卡（只作「曾经有卡」证据，永不参与命中）
                'excludedCards' => $excludedCards,
                // 历史实例明细（唯一历史事实源；过期有余额 / 炸弹会员的输入）
                'historyCards' => $historyCards,
                // 会员级权益摘要（唯一汇总口径；判定层会复算校验）
                'entitlement' => [
                    'mainCard' => $mainInstance !== null ? $mainInstance['title'] : '—',
                    'mainCardState' => $mainCardState,
                    'expireDate' => $expireDate,
                    'expireDateState' => $expireDateState,
                    'nextExpireDays' => $nextExpireDays,
                    'usableCount' => (int) floor($usableCount),
                    'displayCount' => $hasCountCard ? (int) floor($countResidue) : 0,
                    'storedValue' => round($storedValue, 2),
                    'historyBalance' => [
                        'sections' => (int) floor($historySections),
                        'amount' => round($historyAmount, 2),
                        'count' => count($history),
                    ],
                    'counts' => [
                        'current' => count($current),
                        'unactivated' => count($unactivated),
                        'history' => count($history),
                        'excluded' => count($excluded),
                        'usable' => count(array_filter($current, fn ($c) => CardLifecycle::isUsable($c))),
                        'exhausted' => count(array_filter($current, fn ($c) => CardLifecycle::isExhausted($c))),
                        'unknown' => count(array_filter($current, fn ($c) => CardLifecycle::isUnknown($c))),
                    ],
                ],
                // §3.6：体验/赠品卡明确排除在「有卡」之外（快照复算：只有礼包卡的 765 人必须落 P5）
                'hasAnyCard' => $allInstances !== [],
                'hasActiveEntitlement' => $current !== [] || $unactivated !== [],
                'expiredBalance' => [
                    'in' => $balanceCards > 0,
                    'sections' => (int) floor($balanceSections),
                    'amount' => round($balanceAmount, 2),
                    'cards' => $balanceCards,
                ],
                'dataVersion' => CardLifecycle::DATA_VERSION,
                'syncDegraded' => $syncDegraded,
            ],
            // 有效卡项明细（current ∪ unactivated）：保留旧 9 键 + 实例身份/生命周期新键（§1.4）
            'cards_list' => array_values(array_map(function (array $card) {
                return [
                    // ── 保留键（旧前端/旧判定仍可读）──────────────────────
                    'title' => $card['title'],
                    // v3.9：节 | 天 | 元（不再把储值卡落成「节」）
                    'unit' => $card['unit'],
                    // NULL = 未知；次卡=节、期限卡=剩余天数（由 deadline 推导）、储值卡=元
                    'residue' => $card['remaining'] === null
                        ? null
                        : ($card['type'] === CardLifecycle::TYPE_STORED
                            ? round($card['remaining'], 2)
                            : (int) floor($card['remaining'])),
                    'bound' => $card['type'] === CardLifecycle::TYPE_COUNT
                        ? ($card['bound'] === null ? null : (int) floor($card['bound']))
                        : null,
                    'deadline' => $card['deadline'],
                    'status' => $card['statusLabel'] ?? (CardLifecycle::STATUS_LABELS[$card['statusCode'] ?? ''] ?? ''),
                    'unactivated' => $card['state'] === 'unactivated',
                    'type' => (string) $card['type'],
                    // A-02：未知必须保留 null（契约 §1.4「两者皆 0 ⇒ null」）；
                    // 写成 0 会让判定层再也区分不了「有效期未知」与「有效期 0 天」。
                    'validDays' => $card['validDays'] === null ? null : (int) $card['validDays'],

                    // ── 新增键：实例身份与生命周期（缺身份时判定层降级归因）────
                    'id' => $card['id'],
                    'cardNo' => $card['cardNo'],
                    'cardId' => $card['cardId'],
                    // createTime / soldAt 同义（发卡日）：前者是判定层契约键，后者是业务命名
                    'createTime' => $card['createTime'],
                    'soldAt' => $card['createTime'],
                    'activateTime' => $card['activateTime'],
                    'activatedAt' => $card['activateTime'],
                    'statusCode' => $card['statusCode'],
                    'residueDays' => $card['residueDays'] === null ? null : (int) floor($card['residueDays']),
                    'residueSource' => $card['remainingSource'],
                    // 生命周期派生（判定层会自行复算，这里落库供展示与审计）
                    'expired' => $card['state'] === 'history'
                        || ($card['daysUntil'] !== null && $card['daysUntil'] < 0),
                    'daysLeft' => $card['daysUntil'],
                    'valueType' => match ($card['type']) {
                        CardLifecycle::TYPE_TIME => 'time',
                        CardLifecycle::TYPE_STORED => 'stored',
                        default => 'count',
                    },
                    'isTaste' => $card['isTaste'],
                    'tasteReason' => $card['tasteReason'],
                    'state' => $card['state'],
                    // 系列序号由 annotateSeries 直接挂在实例上（A-01：不再用 identity map）
                    'seriesIndex' => $card['seriesIndex'] ?? null,
                    'seriesCount' => $card['seriesCount'] ?? null,
                    // 同步侧已判定的降级码（如期限卡脏 residue 被忽略、缺身份字段）
                    'degraded' => $card['degraded'],
                ];
            }, array_merge($current, $unactivated))),
        ];
    }

    /**
     * 已耗**次数**（次卡的权威推导规则）。
     *
     * ══════════════════════════════════════════════════════════════════════════
     * 为什么需要它：`usage_total` 是**金额**（元），不是次数。
     * 旧实现把它直接加到 `residue_amount`（节）上：
     *   王素红「定制私教30节」residue=29、usage_total=466.63 ⇒ bound=29+466.63=495.63
     *   ⇒ 29/495.63 = 5.85% ⇒ 被误判「待续费·紧急」（界面上的 5.9%）
     *   正确：已耗 = 466.63/466.6333 = 1 次 ⇒ bound=30 ⇒ 29/30 = 96.7% ⇒ 不该提醒。
     *
     * ══════════════════════════════════════════════════════════════════════════
     * 权威规则（优先级 + 理由，可复现）：
     *
     *   1. **`usage_total / curr_unit_cash_value`**（金额 ÷ 单次折算价 = 已耗次数）
     *   2. 回退 **`consume_amount_format`**（形如 `"1次"` 的文本）
     *   3. 两者都用不了 → 返回 **null**（显式降级，见 `$underivable` / `countBoundUnderivable`）
     *
     * 为何金额优先？——**两个独立判别实验**（脚本见
     * `docs/口径/会员卡项判定与待续费口径.md` 的「判别实验」一节，数据用本机冻结样本
     * `ky_cards_live_20260924.json`，2954 张卡）：
     *
     *  **实验 A｜独立基准：成交价 ÷ 单次折算价 = 购买总次数**
     *  （`deal_price / curr_unit_cash_value` 恰为整数且 ≥ 剩余节数的卡 = 331 张，作为基准）：
     *     金额口径与基准一致 **264/331 = 79.8%**
     *     文本口径与基准一致 **131/331 = 39.6%**
     *    ⇒ 分歧样本里金额口径对 149、文本口径只对 16 —— 金额路径压倒性更准。
     *
     *  **实验 B｜两来源在可比卡上的互斥性**（captain 基线，已复现）：
     *  `status ∈ {4,5,7}` 且 `curr_unit_cash_value > 0` 且文本可解析的次卡 = **431 张**，
     *  两来源一致仅 **179 张**、不一致 **252 张** ⇒ **二者不可互替**，
     *  正是本函数必须钉死单一权威规则、而不能「哪个能用用哪个」的依据。
     *
     * 为何 `round` 而非 `floor`：`usage_total` 是二进位浮点累加值（如 466.63），
     * 除以单价后接近整数但常有极小误差；`floor(0.9999) = 0` 会把「已消耗 1 次」抹成 0，
     * 使分母退化成「剩余节数」、占比恒为 100%。实验 A 实测 `round` 一致率 79.8%
     * （`floor` 仅 59.2%，`ceil` 77.3%），故取 `round`。
     *
     * 为何不用 `initial_amount` / `amount` 当总次数：599 张在用次卡里 **380 张**
     * `residue_amount > initial_amount`（例：`residue=29, initial_amount="1次"`），
     * 它是「赠送次数/当前绑定节数」语义，用它做分母会造出 >100% 的荒谬占比。
     *
     * ⚠️ 不要为了「让数字好看」把 gap 平摊或取两来源的最大/最小：
     * 那会把两个都不可靠的口径混成一个第三口径，且没有任何独立基准支持它。
     *
     * @return int|null 已耗次数；推导不出时返回 null（调用方必须显式降级，不得猜）
     */
    private static function consumedCount(array $card): ?int
    {
        // 1) 金额 ÷ 单次折算价 —— 权威来源
        $unit = self::toNum($card['curr_unit_cash_value'] ?? 0);
        if ($unit > 0) {
            $consumed = self::toNum($card['usage_total'] ?? 0) / $unit;

            // 负值/非有限值一律视为不可用（上游异常给出脏数据时不猜）
            if (is_finite($consumed) && $consumed >= 0) {
                return (int) round($consumed);
            }
        }

        // 2) 回退：consume_amount_format 的「N次」文本
        $raw = (string) ($card['consume_amount_format'] ?? '');
        if (preg_match('/(-?\d+(?:\.\d+)?)\s*次/u', $raw, $m)) {
            $value = (float) $m[1];

            return $value >= 0 ? (int) round($value) : null;
        }

        // 3) 推导不出 —— 显式降级，交由上层的 underivable 计数上报
        return null;
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

    /**
     * 统计本次同步里去重后的已签到预约数（仅用于同步结果展示）。
     *
     * 这里不再顺手算出勤：出勤在同步末尾由 attendanceFromFacts 从预约事实表整段重算，
     * 在本函数里算一次会被整段覆盖，纯属白跑。
     */
    private static function trackSignedBooking(array $booking, string $path, array &$seenBookings): void
    {
        if ((string) ($booking['status_desc'] ?? '') !== '已签到') {
            return;
        }
        $memberId = self::pick($booking, ['m_id', 'member_id']);
        $visitedAt = self::date($booking['start_time'] ?? $booking['course_date'] ?? null);
        if ($memberId === '' || ! $visitedAt) {
            return;
        }
        $recordId = self::pick($booking, ['id', 'reservation_id']);
        $seenBookings[$path.':'.$recordId.':'.$memberId] = true;
    }

    /**
     * 出勤三窗口：三个连续且等长的 30 天窗口。
     *
     *   M3 近 30 天（含今天）／M2 前 30 天／M1 再前 30 天
     *
     * 原实现按「近三个完整自然月」统计，本月完全不计入，导致：
     *  - 会员本月天天来，会员列表里出勤仍显示 0；
     *  - 「待续课」要求 M3 > 0（最近一个月有出勤）时，上月休假、本月恢复训练的会员被漏掉，
     *    即使课时快用完也不进清单。
     * 滚动窗口既包含当期，又保持三档等长（各 30 天）从而互相可比——
     * 这是「出勤降低（M1 > M2 > M3）」「预流失（M2 > 0 且 M3 = 0）」两条规则成立的前提。
     *
     * @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}> [M1, M2, M3] 各自 [起, 止]
     */
    public static function attendanceWindows(CarbonInterface|string|null $today = null): array
    {
        $today = CarbonImmutable::parse($today ?? 'today');

        return [
            // M1 再前 30 天：60~89 天前
            [$today->subDays(89)->startOfDay(), $today->subDays(60)->endOfDay()],
            // M2 前 30 天：30~59 天前
            [$today->subDays(59)->startOfDay(), $today->subDays(30)->endOfDay()],
            // M3 近 30 天：含今天
            [$today->subDays(29)->startOfDay(), $today->endOfDay()],
        ];
    }

    /**
     * 从预约事实表整段重算三个窗口的出勤与最近到店日。
     *
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $windows  [M1, M2, M3] 各自 [起, 止]
     */
    private static function attendanceFromFacts(string $venue, array $windows): array
    {
        $attendance = [];
        $earliest = $windows[0][0] ?? null;
        if (! $earliest) {
            return $attendance;
        }
        KyBooking::query()
            ->where('venue', $venue)
            ->where('status', 'signed')
            ->whereNotNull('member_id')
            ->where('member_id', '!=', '')
            ->where('start_at', '>=', $earliest)
            ->orderBy('id')
            ->chunkById(1000, function ($bookings) use (&$attendance, $windows) {
                foreach ($bookings as $booking) {
                    $memberId = (string) $booking->member_id;
                    $date = CarbonImmutable::parse($booking->start_at);
                    $attendance[$memberId]['last_visit'] = max(
                        $attendance[$memberId]['last_visit'] ?? '0000-00-00',
                        $date->toDateString()
                    );
                    foreach ($windows as $index => [$from, $to]) {
                        if ($date->betweenIncluded($from, $to)) {
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
        // 课型判定统一走 courseKindFrom()（映射与缺失兜底的理由见该函数注释）。
        // 本处**不再**按接口来源兜底：读侧的授权判据（privateStudentKeys 取
        // course_kind='private'）会跟着兜底放宽，故缺失一律按 group（失败关闭）。
        $courseKind = courseKindFrom($row['course_type'] ?? null);
        $now = now();

        return [
            'source_key' => "{$venueId}:{$type}:{$identity}",
            'venue' => $venue,
            'booking_type' => $type,
            'course_kind' => $courseKind,
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
        // 按唯一教师名解析一次 id（一节课几十条事实、老师只有几个），再回填到每一行
        $teacherIds = [];
        foreach (array_unique(array_column($facts, 'teacher_name')) as $tn) {
            if ((string) $tn !== '') {
                $teacherIds[$tn] = staffUserId((string) $tn);
            }
        }
        foreach ($facts as &$fact) {
            $fact['teacher_user_id'] = $teacherIds[$fact['teacher_name'] ?? ''] ?? null;
        }
        unset($fact);

        foreach (array_chunk($facts, 500) as $chunk) {
            $changed = self::changedFacts('ky_bookings', $chunk, [
                'member_id', 'member_name', 'phone', 'start_at', 'course_name', 'teacher_name',
                'teacher_user_id',
                'status_raw', 'status', 'is_trial', 'course_kind', 'raw',
            ]);
            KyBooking::upsert($changed, ['source_key'], [
                'member_id', 'member_name', 'phone', 'start_at', 'course_name', 'teacher_name',
                'teacher_user_id',
                'status_raw', 'status', 'is_trial', 'course_kind', 'raw', 'updated_at',
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
        // 日期解析的唯一实现见 CardLifecycle::parseDate()（同步/判定共用，
        // 避免「同一张卡在同步层是 2026-09-23、在判定层是 2026-09-24」这类跨层偏差）。
        return CardLifecycle::parseDate($value);
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
