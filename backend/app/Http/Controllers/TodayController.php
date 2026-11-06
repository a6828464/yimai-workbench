<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use App\Models\AppSetting;
use App\Models\Customer;
use App\Models\KyBooking;
use App\Models\Lead;
use App\Models\PostClassReview;
use App\Models\Task;
use App\Models\TodoAction;
use App\Models\User;
use App\Services\VisitMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class TodayController extends Controller
{
    /** GET /today/snapshot */
    public function snapshotShow(Request $r)
    {
        $snap = AppSetting::oldest('id')->first()?->snapshot ?? [];

        return ok($snap);
    }

    /** PUT /today/snapshot */
    public function snapshotUpdate(Request $r)
    {
        requireSuper($r);
        $d = $r->validate([
            'todayBookings' => 'required|array',
            'todayBookings.绿地店' => 'required|integer|min:0',
            'todayBookings.东部店' => 'required|integer|min:0',
            'trialBookings' => 'required|array',
            'trialBookings.绿地店' => 'required|integer|min:0',
            'trialBookings.东部店' => 'required|integer|min:0',
            'todayKinds' => 'nullable|array',
            'todayKinds.绿地店' => 'nullable|array',
            'todayKinds.东部店' => 'nullable|array',
        ]);
        $s = setting();
        $s = DB::transaction(function () use ($s, $d, $r) {
            $locked = AppSetting::whereKey($s->id)->lockForUpdate()->first() ?? $s;
            $snap = $locked->snapshot ?? [];
            $snap['todayBookings'] = [
                '绿地店' => (int) ($d['todayBookings']['绿地店'] ?? 0),
                '东部店' => (int) ($d['todayBookings']['东部店'] ?? 0),
            ];
            $snap['trialBookings'] = [
                '绿地店' => (int) ($d['trialBookings']['绿地店'] ?? 0),
                '东部店' => (int) ($d['trialBookings']['东部店'] ?? 0),
            ];
            if (isset($d['todayKinds'])) {
                $snap['todayKinds'] = [
                    '绿地店' => [
                        '私教' => (int) ($d['todayKinds']['绿地店']['私教'] ?? 0),
                        '小班' => (int) ($d['todayKinds']['绿地店']['小班'] ?? 0),
                        '团课' => (int) ($d['todayKinds']['绿地店']['团课'] ?? 0),
                    ],
                    '东部店' => [
                        '私教' => (int) ($d['todayKinds']['东部店']['私教'] ?? 0),
                        '小班' => (int) ($d['todayKinds']['东部店']['小班'] ?? 0),
                        '团课' => (int) ($d['todayKinds']['东部店']['团课'] ?? 0),
                    ],
                ];
            }
            $snap['fetchedAt'] = array_merge((array) ($snap['fetchedAt'] ?? []), [
                '绿地店' => now()->format('Y-m-d H:i:s'),
                '东部店' => now()->format('Y-m-d H:i:s'),
            ]);
            $snap['fetchedBy'] = $r->user()->name;
            $locked->update(['snapshot' => $snap]);

            return $snap;
        });

        // 快照是 /today/summary 的输入（todayBookings / trialBookings / todayKinds /
        // snapshotTime / snapshotAvailable 全部来自它），而 summary 现在有 60 秒缓存。
        // setting()->update() 不会经过任何业务写入路径，不在这里显式失效的话，
        // 超管保存完立刻回工作台会看到**旧快照的数字**，是最容易被当成「保存失败」的形态。
        invalidateBusinessCaches('analytics');

        return ok($s);
    }

    /**
     * GET /today/summary
     *
     * 两条硬口径，改之前先读：
     *
     * 1. **所有指标必须是 SQL 聚合，不得 `->get()` 把 scope 集合拉进内存。**
     *    历史实现在这里拉了整张 customers（`Customer` 没有 `$hidden`，`cards_list` /
     *    `card_stats` 两个 JSON 列会一起 hydrate）与整张 leads，然后在 Collection 上
     *    filter/where 数 4 个标量 —— 双店 1560 会员规模下每次请求要 hydrate ~1600 个
     *    Eloquent 模型、传输数 MB，而**一行行数据都没有被用到**。现在 5 次 COUNT 解决，
     *    逐项等值依据写在 computeSummary() 里。
     *
     * 2. **结果按角色 + 门店收窄，所以缓存键必须包含整个可见范围。**
     *    只按账号 id 做键不够：后台把某人的 roles/venue 改过之后，键不变就会继续读到
     *    旧范围算出来的数字。键里另外带两个业务缓存版本号（member_lists / analytics）——
     *    会员、客资、任务、审批、同步、待办标记这些写入路径都会 bump 其中之一，
     *    因此写入后立刻换键，不依赖 60 秒 TTL 兜底。
     *
     * 缓存 60 秒：与 /analytics/* 同口径（那边也是 60 秒）。本接口是小程序每个 tab
     * onShow 都会打的接口之一，且它与 /today/todo、/analytics/summary 并发命中同一批表。
     */
    public function summary(Request $r)
    {
        $u = $r->user();

        $key = 'today_summary:v'.businessCacheVersion('member_lists')
            .'-'.businessCacheVersion('analytics')
            .':u'.$u->id
            .':'.md5(implode('|', [
                (string) $u->venue,
                implode(',', userRoles($u)),
                implode(',', (array) $u->venues),
            ]));

        return ok(Cache::remember($key, 60, fn () => $this->computeSummary($u)));
    }

    /**
     * /today/summary 的实际计算（只读）；结果被 summary() 缓存 60 秒。
     *
     * @return array<string, mixed>
     */
    private function computeSummary(User $u): array
    {
        // ── 会员侧：4 个标量全部下推为 COUNT ─────────────────────────────────
        //
        // 每次统计都用 clone 出来的独立 Builder。Eloquent\Builder 实现了 __clone()
        // （vendor …/Eloquent/Builder.php:2327，内部再 clone 底层 Query\Builder），
        // 所以 clone 之后叠的 where 不会污染其它几次统计。**不要**改回共用同一个实例：
        // count() 不会重置 query，下一次统计会连上一次的 where 一起算，表现为数字越数越小。
        $custQ = scopeCustomersForUser(Customer::query(), $u);

        // ① 「前端客资」：谓词唯一来源 scopeLeadOnlyCustomers()，与内存版
        //    isLeadOnlyCustomer() 逐条同义（helpers.php:963-980：layer='P5' 且 external_id
        //    不是 'ky:' 开头；external_id 为 NULL 时两边都算「是客资」）。
        //    ⚠ 两者唯一的理论差异是大小写：PHP 的 str_starts_with 区分大小写，SQL 的 LIKE
        //    在本库默认 utf8mb4_*_ci 排序规则下不区分。external_id 的唯一写入点是
        //    KyMemberSyncService，恒写小写 'ky:'，因此该差异在数据层面不可达。
        $customerLeadOnly = scopeLeadOnlyCustomers(clone $custQ)->count();

        // ② 待跟进：next_action_time 是 string()->default('') NOT NULL
        //    （建表 2026_08_26_000001_create_yimai_tables.php），**不存在 NULL 行**，
        //    所以 SQL 的 `!= ''` 不会像常规情况那样把 NULL 行静默漏掉。
        //    比较两侧都是 'Y-m-d H:i:s' 定长串，PHP 的字符串比较与 SQL 的串比较同序
        //    （逐字节、位数对齐），故 `<= $tomorrow.' 23:59:59'` 与内存判断等值。
        $tomorrow = now()->addDay()->toDateString();
        $pendingFollowup = (clone $custQ)
            ->where('next_action_time', '!=', '')
            ->where('next_action_time', '<=', $tomorrow.' 23:59:59')
            ->count();

        // ③ 临期会员：与 filteredIds('待续课') 求交集。清单为空时 whereIn 生成 `0 = 1`，
        //    与 Collection::whereIn([], …) 返回 0 一致。
        $renewalIds = filteredIds('待续课');
        $expiringMembers = (clone $custQ)->whereIn('id', $renewalIds)->count();

        // ④ 未分配负责人（owner 列 default '未分配' NOT NULL）。
        $unassignedOwners = (clone $custQ)->where('owner', '未分配')->count();

        // ── 客资侧：newLeads 与 riskCount 用的是**同一个**计数，只查一次 ──
        $leadQ = scopeLeadsForUser(Lead::query(), $u);
        $newStatusLeads = (clone $leadQ)->where('status', '新留资')->count();

        // 可见范围收敛到 scopeTasksForUser（helpers.php，唯一收口点，含角色不明兜底）。
        // 本处此前自写四个角色分支且**没有兜底**，角色不明账号会把他人任务计进 riskCount。
        $overdueTasks = scopeTasksForUser(Task::query(), $u, 'summary')
            ->where('status', '已逾期')->count();

        $setting = AppSetting::oldest('id')->first();
        $snap = $setting?->snapshot;

        // ---- 今日排课口径（真机反馈：老师侧看到的是全店预约） ----
        //  超管/店长 → 读同步快照（全店，本店键有值、另一店键 0）
        //  老师侧    → **本人今日排课**（授课老师按 teacher_name，服务老师按名下会员手机号）
        //  新媒体    → 0（它没有自己的课；此前 venue 为空，两个门店键都会命中快照，
        //              于是新媒体工作台显示双店全量预约）
        // 老师侧不再读快照：快照是「店」的聚合，拿它当「我的」只能靠猜。
        $teacherSide = userIsTeacherSide($u) && ! userHasAnyRole($u, ['R_SUPER', 'R_MANAGER']);
        $mediaSide = ! $teacherSide && userHasRole($u, 'R_MEDIA') && ! userHasAnyRole($u, ['R_SUPER', 'R_MANAGER']);
        $zeroKinds = ['私教' => 0, '小班' => 0, '团课' => 0];

        if ($teacherSide) {
            [$todayBookings, $trialBookings, $todayKinds] = $this->personalTodaySchedule($u);
            $scopeLabel = $u->venue ? "我的 · {$u->venue}" : '我的';
            // 数据来自预约表事实（不是快照），不存在「未同步」的歧义
            $snapshotAvailable = true;
        } elseif ($mediaSide) {
            $todayBookings = ['绿地店' => 0, '东部店' => 0];
            $trialBookings = ['绿地店' => 0, '东部店' => 0];
            $todayKinds = ['绿地店' => $zeroKinds, '东部店' => $zeroKinds];
            $scopeLabel = $u->venue ? "本店 · {$u->venue}" : '双店';
            $snapshotAvailable = true;
        } else {
            $todayBookings = [
                '绿地店' => (! $u->venue || $u->venue === '绿地店') ? ($snap['todayBookings']['绿地店'] ?? 0) : 0,
                '东部店' => (! $u->venue || $u->venue === '东部店') ? ($snap['todayBookings']['东部店'] ?? 0) : 0,
            ];
            $trialBookings = [
                '绿地店' => (! $u->venue || $u->venue === '绿地店') ? ($snap['trialBookings']['绿地店'] ?? 0) : 0,
                '东部店' => (! $u->venue || $u->venue === '东部店') ? ($snap['trialBookings']['东部店'] ?? 0) : 0,
            ];
            $todayKinds = isset($snap['todayKinds']) ? [
                '绿地店' => (! $u->venue || $u->venue === '绿地店') ? (array) ($snap['todayKinds']['绿地店'] ?? $zeroKinds) : $zeroKinds,
                '东部店' => (! $u->venue || $u->venue === '东部店') ? (array) ($snap['todayKinds']['东部店'] ?? $zeroKinds) : $zeroKinds,
            ] : null;
            $scopeLabel = $u->venue ? "本店 · {$u->venue}" : '双店';
            $snapshotAvailable = $snap !== null && is_array($snap['todayBookings'] ?? null);
        }

        return [
            // 新客资口径 = 「前端客资」谓词（P5 且非 ky: 来源）+ 未跟进的留资记录。
            // 不能直接数 layer='P5'：卡项全部过期的**正式会员**也会落 P5，
            // 那会把会员误报成新客资，让店长去「跟进」一个已经在册的会员。
            'newLeads' => $customerLeadOnly + $newStatusLeads,
            'pendingFollowup' => $pendingFollowup,
            'expiringMembers' => $expiringMembers,
            'riskCount' => $overdueTasks + $unassignedOwners + $newStatusLeads,
            'pendingApprovals' => userHasRole($u, 'R_MEDIA') ? 0 : Approval::where('status', 'like', '待%')->when(userHasRole($u, 'R_MANAGER'), fn ($q) => $q->where('venue', $u->venue))->count(),
            'todayBookings' => $todayBookings,
            'trialBookings' => $trialBookings,
            'todayKinds' => $todayKinds,
            'scopeLabel' => $scopeLabel,
            'snapshotTime' => userHasAnyRole($u, ['R_SUPER', 'R_MANAGER'])
                ? (is_array($snap['fetchedAt'] ?? null) ? implode(' / ', array_values(array_filter((array) $snap['fetchedAt']))) : (string) ($snap['fetchedAt'] ?? ''))
                : '',
            // 区分「今天真没预约」与「该店从未同步/快照缺失」，前端据此给出可读提示而非误显示 0
            'snapshotAvailable' => $snapshotAvailable,
        ];
    }

    /**
     * 老师侧「本人今日排课」：按门店汇总的预约数 / 体验课数 / 课型构成。
     *
     * 口径与 `KyMemberSyncService` 写快照时完全一致（排除 cancelled / no_show），
     * 否则同一个「今日预约」在店长页与老师页会对不上。
     *
     * @return array{0: array<string, int>, 1: array<string, int>, 2: array<string, array<string, int>>}
     */
    private function personalTodaySchedule(User $u): array
    {
        $rows = scopeBookingsForUser(
            KyBooking::query()->whereBetween('start_at', [now()->startOfDay(), now()->endOfDay()]),
            $u
        )->get(['venue', 'start_at', 'status', 'course_kind', 'is_trial', 'raw']);

        $bookings = ['绿地店' => 0, '东部店' => 0];
        $trials = ['绿地店' => 0, '东部店' => 0];
        $kinds = [
            '绿地店' => ['私教' => 0, '小班' => 0, '团课' => 0],
            '东部店' => ['私教' => 0, '小班' => 0, '团课' => 0],
        ];

        foreach ($rows as $b) {
            if (in_array((string) $b->status, ['cancelled', 'no_show'], true)) {
                continue;
            }
            $venue = (string) $b->venue;
            if (! array_key_exists($venue, $bookings)) {
                // 非两店的脏数据不计：多出一个键会让前端门店卡片错位
                continue;
            }
            $bookings[$venue]++;
            if ($b->is_trial) {
                $trials[$venue]++;
            }
            $kind = KyBooking::KIND_LABELS[$b->courseKind()] ?? '团课';
            $kinds[$venue][$kind] = ($kinds[$venue][$kind] ?? 0) + 1;
        }

        return [$bookings, $trials, $kinds];
    }

    /**
     * GET /today/teacher-overview：老师侧工作台概览。
     *
     * 替换前端原来的估算口径（课时 = 当日预约 × 0.6），改为读真实排课事实：
     *  - 授课老师（私教主教练）：按 ky_bookings.teacher_name 取本人排课，并给出私教/小班/团课构成
     *  - 服务老师（会籍顾问）：本人在籍会员的客资漏斗
     */
    public function teacherOverview(Request $r)
    {
        $u = $r->user();
        abort_unless(userIsTeacherSide($u), 403, '仅老师侧角色可访问');

        $roles = userRoles($u);
        $start = Carbon::parse((string) $r->query('startDate', now()->startOfMonth()->toDateString()))->startOfDay();
        $end = Carbon::parse((string) $r->query('endDate', now()->toDateString()))->endOfDay();
        $isCoach = userHasRole($u, 'R_TEACHER');

        // 可见会员：统一走 scope（服务老师＝本人名下；授课老师＝本人私教学员 ∪ 本人会籍会员）
        $customers = scopeCustomersForUser(Customer::query(), $u)
            ->get(['id', 'name', 'consultant', 'owner', 'consultant_user_id', 'owner_user_id', 'external_id', 'phone']);
        // 「我的会员」按 id 或姓名/别名并集判断，与 scopeCustomersForUser 同口径
        $serviceMemberCount = $customers->filter(
            fn ($c) => staffOwnsRow($u, $c, 'consultant_user_id', 'consultant')
                || staffOwnsRow($u, $c, 'owner_user_id', 'owner')
        )->count();

        $teachStudentCount = 0;
        if ($isCoach) {
            $keys = privateStudentKeys($u);
            $teachStudentCount = $customers->filter(
                fn ($c) => in_array((string) $c->external_id, $keys['external_ids'], true)
                    || in_array((string) $c->phone, $keys['phones'], true)
            )->count();
        }

        // 真实排课：授课老师取本人课表；服务老师看本店课表（用于关联自己的会员）
        $bookingQ = fn () => KyBooking::query()
            ->where('venue', $u->venue)
            ->when($isCoach, fn ($q) => $q->where(staffOwnerFilter($u, 'teacher_user_id', 'teacher_name')));

        $bookings = $bookingQ()->whereBetween('start_at', [$start, $end])
            ->get(['start_at', 'status', 'course_kind', 'is_trial', 'member_id', 'member_name', 'phone']);
        $signed = $bookings->where('status', 'signed');
        $classCount = $signed->count();
        $kindCount = [
            '私教' => $signed->where('course_kind', 'private')->count(),
            '小班' => $signed->where('course_kind', 'small')->count(),
            '团课' => $signed->where('course_kind', 'group')->count(),
        ];

        // 服务人次 = 该期间实际服务到的不同学员数
        $identityOf = fn ($b) => (string) $b->phone !== '' ? 'p:'.$b->phone : ((string) $b->member_id !== '' ? 'm:'.$b->member_id : '');
        $servedCount = $signed->map($identityOf)->filter()->unique()->count();

        $byDate = [];
        foreach ($signed as $b) {
            $d = $b->start_at?->toDateString();
            if (! $d) {
                continue;
            }
            $byDate[$d]['classes'] = ($byDate[$d]['classes'] ?? 0) + 1;
            $id = $identityOf($b);
            if ($id !== '') {
                $byDate[$d]['members'][$id] = true;
            }
        }

        // 个人客资范围：授课老师＝本人上过体验课的＋本人作为会籍顾问的＋本人私教学员；
        // 服务老师＝本人名下的。抽成闭包，供下面的列表与漏斗共用同一范围。
        $personalLeadScope = function () use ($u, $isCoach) {
            $q = Lead::query()->where('venue', $u->venue);
            $q->when($isCoach, function ($q) use ($u) {
                $keys = privateStudentKeys($u);
                $q->where(function ($w) use ($u, $keys) {
                    $w->where(staffOwnerFilter($u, 'service_teacher_user_id', 'service_teacher'))->orWhere(staffOwnerFilter($u, 'trial_teacher_user_id', 'trial_teacher'));
                    if ($keys['phones'] !== []) {
                        // 私教课学员通道同样要过「客资已有真人服务老师」这道门槛
                        // （与 scopeLeadsForUser / userCanAccessLead 同一口径）：
                        // 否则无主客资仍会经由这里计进老师的漏斗与「我的客资」。
                        $w->orWhere(function ($x) use ($keys) {
                            $x->whereIn('phone', $keys['phones']);
                            scopeAssignedLeads($x);
                        });
                    }
                });
            }, fn ($q) => $q->where(staffOwnerFilter($u, 'service_teacher_user_id', 'service_teacher')));

            return $q;
        };

        // 个人客资列表：phone / name 必须一起取（身份键要靠它们，漏取会让人塌成同一个 key）；
        // service_teacher_user_id 一起取（「我的客资」按 id 或姓名并集判断）
        $leadRows = $personalLeadScope()
            ->get(['id', 'phone', 'name', 'status', 'deal_at', 'deal_amount', 'lead_date', 'service_teacher', 'service_teacher_user_id']);

        // 到店/成交口径与全店**完全同源**（VisitMetrics：三来源并集 + 分子分母同源），
        // 只把范围收窄成本人。此前这里只认留资状态一条来源，于是「老师只勾了体验课卡片、
        // 没把状态推进到已体验」的到店在老师侧整批消失，与老板看板对不上。
        $myIdentities = [];
        foreach ($leadRows as $l) {
            $id = VisitMetrics::identityOf($l->phone, (string) $l->name);
            if ($id !== '') {
                $myIdentities[$id] = true;
            }
        }
        // 预约来源只取「同时也属于我的客资」的签到：服务老师是本店课表，
        // 不加这层会把他人的到店算进个人漏斗
        $myTrialBookings = $bookings->filter(function ($b) use ($myIdentities) {
            $id = VisitMetrics::identityOf($b->phone, (string) ($b->member_id ?: $b->member_name));

            return $id !== '' && isset($myIdentities[$id]);
        });

        $visit = VisitMetrics::visitSet($personalLeadScope(), $myTrialBookings, $start->toDateString(), $end->toDateString());
        $visitSet = $visit['identities'];
        $visitCount = count($visitSet);

        $deal = VisitMetrics::dealSet($personalLeadScope(), $visitSet, $start->toDateString(), $end->toDateString());
        $dealCount = $deal['count'];
        $dealAmount = $deal['amount'];

        $leadCount = $leadRows->filter(fn ($l) => $l->lead_date
            && Carbon::parse($l->lead_date)->between($start, $end))->count();

        // 逐日客资数（服务老师趋势图用）。数据取自同一批 $leadRows，口径与上面的漏斗一致。
        $leadsByDate = [];
        foreach ($leadRows as $l) {
            $d = substr((string) $l->lead_date, 0, 10);
            if ($d !== '') {
                $leadsByDate[$d] = ($leadsByDate[$d] ?? 0) + 1;
            }
        }

        // 今日课程
        $todayClasses = $bookingQ()
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->whereBetween('start_at', [now()->startOfDay(), now()->endOfDay()])
            ->orderBy('start_at')
            ->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'time' => $b->start_at?->format('H:i') ?? '',
                'memberName' => (string) $b->member_name,
                'course' => (string) $b->course_name,
                'kind' => KyBooking::KIND_LABELS[$b->courseKind()] ?? '团课',
                'isTrial' => (bool) $b->is_trial,
                'status' => (string) $b->status,
                'teacher' => (string) $b->teacher_name,
            ])->all();

        $series = [];
        for ($d = $start->copy(); $d->lte($end); $d = $d->addDay()) {
            $key = $d->toDateString();
            $series[] = [
                'date' => $key,
                'label' => $d->format('m-d'),
                'classes' => $byDate[$key]['classes'] ?? 0,
                'served' => count($byDate[$key]['members'] ?? []),
                // 服务老师的趋势图画这一列；此前 series 里没有它，前端只能取到 0
                'leads' => $leadsByDate[$key] ?? 0,
            ];
        }

        return ok([
            'role' => primaryRole($roles),
            'roles' => $roles,
            'roleLabel' => implode(' + ', array_map('roleLabel', array_values(array_filter(
                ROLE_PRECEDENCE,
                fn ($x) => in_array($x, $roles, true)
            )))),
            'scopeLabel' => $u->venue ? "本店 · {$u->venue}" : '未设置门店',
            'startDate' => $start->toDateString(),
            'endDate' => $end->toDateString(),
            'memberCount' => $customers->count(),
            'serviceMemberCount' => $serviceMemberCount,
            'teachStudentCount' => $teachStudentCount,
            'leadCount' => $leadCount,
            'resourceCount' => $leadRows->count(),
            'newResourceCount' => $leadRows->where('status', '新留资')->count(),
            // 内存判断必须用 staffOwnsRow：staffOwnerFilter 返回的是「给 Builder 用的闭包」，
            // 传给集合的 where() 会被当成谓词逐条调用，而那个闭包没有返回值，结果恒为空集。
            'myLeadCount' => $leadRows->filter(
                fn ($l) => staffOwnsRow($u, $l, 'service_teacher_user_id', 'service_teacher')
            )->count(),
            'visitCount' => $visitCount,
            'dealCount' => $dealCount,
            'dealAmount' => $dealAmount,
            'dealRate' => $visitCount > 0 ? round($dealCount / $visitCount * 100, 1) : 0,
            'classCount' => $classCount,
            'servedCount' => $servedCount,
            'kindCount' => $kindCount,
            'todayClassCount' => count($todayClasses),
            'todayClasses' => $todayClasses,
            'series' => $series,
        ]);
    }

    /** GET /today/followups */
    public function followups(Request $r)
    {
        $u = $r->user();
        if (userHasRole($u, 'R_MEDIA')) {
            return ok([]);
        }
        // P5 在这里的用途是「把前端客资也纳入待跟进」，所以必须排除 ky: 来源：
        // 卡项全部过期的正式会员同样落 P5，若一并收进来，
        // 店长的「待跟进」列表会被在册会员占位，而真正的留资反而排在后面（limit 6）。
        $q = scopeCustomersForUser(Customer::query(), $u)
            ->where(function ($w) {
                $w->whereIn('layer', ['P0', 'P1'])
                    ->orWhere(fn ($inner) => scopeLeadOnlyCustomers($inner));
            });

        return ok($q->orderBy('id')->limit(6)->get()->map(function ($c) {
            $arr = camel($c);
            $days = $c->last_visit ? (int) ((time() - strtotime($c->last_visit)) / 86400) : 9999;
            $arr['lastVisitDays'] = $days;

            return $arr;
        }));
    }

    /** GET /today/alerts */
    public function alerts(Request $r)
    {
        $u = $r->user();
        $alerts = [];

        $leadQ = scopeLeadsForUser(Lead::query(), $u)->where('status', '新留资');
        foreach ($leadQ->orderByDesc('id')->limit(2)->get() as $l) {
            $alerts[] = ['id' => 9000 + $l->id, 'level' => '高', 'text' => "[{$l->venue}] 新客资 {$l->name} 待首响（{$l->source}）", 'action' => '24小时内完成首轮联系'];
        }

        // 可见范围收敛到 scopeTasksForUser（唯一收口点）。本处此前**完全没有兜底**，
        // 角色不明账号会看到双店他人任务的「标题-客户姓名」（t10/t15 查实）。
        $taskQ = scopeTasksForUser(Task::query()->where('status', '已逾期'), $u, 'alerts');
        foreach ($taskQ->limit(4)->get() as $t) {
            $alerts[] = ['id' => 100 + $t->id, 'level' => '中', 'text' => "任务「{$t->title}-{$t->customer_name}」已逾期", 'action' => '提醒责任人完成闭环'];
        }

        $expiring = scopeCustomersForUser(Customer::query(), $u)
            ->when(userHasRole($u, 'R_MEDIA'), fn ($q) => $q->whereRaw('1 = 0'))
            ->whereIn('id', filteredIds('待续课'))->orderBy('expire_date')->limit(2)->get();
        foreach ($expiring as $c) {
            $alerts[] = ['id' => 200 + $c->id, 'level' => '中', 'text' => "{$c->name} 卡项临近到期（剩余{$c->remain_times}节）", 'action' => '确认续费窗口沟通结果'];
        }

        return ok($alerts);
    }

    /**
     * 把排课查询收敛到「与当前用户相关」的课。
     *
     * 待办页此前只按门店过滤，老师端看到的是整店的课，夹带大量与自己无关的信息。
     * 口径与可见范围保持一致：
     *  - 授课老师：自己上的课（teacher_name）
     *  - 服务老师：自己名下会员的课（按手机号匹配会员归属）
     *  - 店长/超管/新媒体：不额外收敛，维持门店/全局视图
     */
    private static function scopeBookingsToUser($query, $u): void
    {
        if (userHasRole($u, 'R_TEACHER')) {
            $query->where('teacher_name', (string) $u->name);

            return;
        }
        if (! userHasRole($u, 'R_SERVICE')) {
            return;
        }
        $phones = Customer::query()
            ->where('venue', $u->venue)
            ->where(fn ($w) => $w->where(staffOwnerFilter($u, 'consultant_user_id', 'consultant'))->orWhere(staffOwnerFilter($u, 'owner_user_id', 'owner')))
            ->where('phone', '!=', '')
            ->pluck('phone')
            ->all();
        $query->whereIn('phone', $phones !== [] ? $phones : ['__none__']);
    }

    /** GET /today/todo */
    public function todo(Request $r)
    {
        $u = $r->user();
        $today = now()->startOfDay();
        $isMedia = userHasRole($u, 'R_MEDIA');
        $isSuper = userHasRole($u, 'R_SUPER');

        // 今日已处理的待办（全店共享：任何人标记，全员消隐，留痕可溯）
        $doneActions = TodoAction::query()->whereDate('action_date', $today->toDateString())->get()->keyBy('todo_key');
        $doneInfo = function (string $key) use ($doneActions): array {
            $rec = $doneActions->get($key);
            if (! $rec) {
                return ['done' => false];
            }

            return [
                'done' => true,
                'doneAction' => (string) $rec->action,
                'doneBy' => (string) $rec->user_name,
                'doneAt' => $rec->created_at?->timezone(config('app.timezone'))->format('H:i'),
                'doneRemark' => (string) $rec->remark,
            ];
        };

        // 五清单口径复用会员管理引擎，一次算好 id => 清单集合 映射
        $lists = memberListIds();
        $listMap = [];
        foreach (['待续课', '预流失', '待复活', '出勤降低', 'VIP'] as $key) {
            foreach ($lists[$key] ?? [] as $id) {
                $listMap[$id][] = $key;
            }
        }

        $scopedCustomers = scopeCustomersForUser(Customer::query(), $u)->get()->map(fn ($c) => camel($c));
        $customerByPhone = $scopedCustomers->filter(fn ($c) => (string) $c['phone'] !== '')->keyBy('phone');
        $customerByExternal = $scopedCustomers->filter(fn ($c) => (string) ($c['externalId'] ?? '') !== '')
            ->keyBy(fn ($c) => preg_replace('/^ky:\d+:/', '', (string) $c['externalId']));
        $customerByName = $scopedCustomers->keyBy(fn ($c) => $c['venue'].'|'.$c['name']);
        $matchCustomer = function (string $phone, string $memberId, string $name, string $venue) use ($customerByPhone, $customerByExternal, $customerByName) {
            if ($phone !== '' && isset($customerByPhone[$phone])) {
                return $customerByPhone[$phone];
            }
            if ($memberId !== '' && isset($customerByExternal[$memberId])) {
                return $customerByExternal[$memberId];
            }
            if ($name !== '' && isset($customerByName[$venue.'|'.$name])) {
                return $customerByName[$venue.'|'.$name];
            }

            return null;
        };

        /*
         * 严格匹配器：**只认 memberId 与 phone 两条精确路径，name 兜底返回 null**。
         *
         * 为什么另开一个而不是复用上面的 $matchCustomer：调用方的后果不对称。
         *  - 落列表（null）：员工多点一次，代价 2 秒；
         *  - 跳错人（name 误命中）：员工会看到**另一个同名客户**的卡项余额、出勤、续费评估，
         *    并据此下判断 —— 不报错、不提示、当场发现不了。馆主 2026-11 拍板：宁可 null。
         *
         * 谁用它：`reviews`（课后分析）与 `trials`（今日体验课）两组的 `customerId`。
         * 这两组此前只给 bookingId / 手机号，前端只能落到列表页，员工得自己找人；
         * 补 customerId 后可直接跳会员详情。其余六组的 `id` 本身就是会员/留资 id，不受影响。
         *
         * 手机号必须先归一（只留数字）：ky_bookings.phone 可能是 '+8613800000002' 或带分隔符的
         * 原始串，而 customers.phone 与同步写入都是纯数字。归一口径沿用本方法既有写法
         * `preg_replace('/\D+/', '', …)`（bookings 组就是这么做的），不另造函数。
         */
        $matchCustomerStrict = function (string $phone, string $memberId) use ($customerByPhone, $customerByExternal) {
            $digits = preg_replace('/\D+/', '', $phone) ?? '';

            // 只去掉分隔符还不够：本仓库**真实存在**带前缀的号码形态
            // （helpers.php 的 privateStudentKeys 注释里记着 `+8613800000002`、`01380000001`
            //  这两种值会原样留在 ky_bookings.phone 里）。所以再补两档等价形态：
            //   13 位且以 86 开头 → 去掉国家码；12 位且以 0 开头 → 去掉长途前缀。
            // 只影响匹配、不改写入，也不动既有 $matchCustomer（bookings 组保持原行为）。
            $candidates = [$digits];
            if (strlen($digits) === 13 && str_starts_with($digits, '86')) {
                $candidates[] = substr($digits, 2);
            } elseif (strlen($digits) === 12 && str_starts_with($digits, '0')) {
                $candidates[] = substr($digits, 1);
            }
            foreach ($candidates as $candidate) {
                if ($candidate !== '' && isset($customerByPhone[$candidate])) {
                    return $customerByPhone[$candidate];
                }
            }
            if ($memberId !== '' && isset($customerByExternal[$memberId])) {
                return $customerByExternal[$memberId];
            }

            return null;
        };
        $daysBetween = fn ($date) => $date ? (int) now()->startOfDay()->diffInDays($date, false) : null;
        $serviceFlags = function (array $c) use ($listMap, $daysBetween): array {
            $flags = $listMap[$c['id']] ?? [];
            if (($c['evalLevel'] ?? null) === 'low') {
                $flags[] = '评估低分';
            }
            if (! empty($c['needsHelp'])) {
                $flags[] = '需协助';
            }
            if (($daysBetween($c['expireDate']) ?? 99) >= 0 && ($daysBetween($c['expireDate']) ?? 99) <= 7) {
                $flags[] = '7天内到期';
            }

            return array_values(array_unique($flags));
        };

        // ---- 今日预约（随心瑜预约事实，排除已取消/爽约） ----
        $bookings = [];
        $bookingTrialCount = 0;
        // 今天约课的会员 id 集合：待续费 tab 只展示「待续课 && 今天约课」的人
        $todayBookedCustomerIds = [];
        if (! $isMedia) {
            $bookingQ = KyBooking::query()
                ->whereBetween('start_at', [$today->copy(), $today->copy()->endOfDay()])
                ->whereNotIn('status', ['cancelled', 'no_show']);
            if (! $isSuper) {
                $bookingQ->where('venue', $u->venue);
            }
            self::scopeBookingsToUser($bookingQ, $u);
            foreach ($bookingQ->orderBy('start_at')->get() as $b) {
                $phone = preg_replace('/\D+/', '', (string) $b->phone) ?? '';
                $customer = $matchCustomer($phone, (string) $b->member_id, (string) $b->member_name, (string) $b->venue);
                // 与课程事实/课后分析同源：走模型访问器（列值优先，缺失时兜底见 courseKindFrom）
                $kind = KyBooking::KIND_LABELS[$b->courseKind()] ?? '团课';
                $bookings[] = [
                    'id' => $b->id,
                    'key' => 'booking:'.$b->id,
                    'time' => $b->start_at?->format('H:i'),
                    'memberName' => (string) $b->member_name,
                    // 预约行 KY 未带手机号时，回填匹配到的会员档案手机号
                    'phone' => $phone !== '' ? $phone : (string) ($customer['phone'] ?? ''),
                    'phoneTail' => $phone !== '' ? substr($phone, -4) : '',
                    'venue' => (string) $b->venue,
                    'course' => (string) $b->course_name,
                    'kind' => $kind,
                    'teacher' => (string) $b->teacher_name,
                    'status' => (string) $b->status,
                    'isTrial' => (bool) $b->is_trial,
                    'customerId' => $customer['id'] ?? null,
                    'lists' => $customer ? $serviceFlags($customer) : [],
                    'birthdayToday' => $customer ? isBirthdayToday($customer['birthday'] ?? null) : false,
                ] + $doneInfo('booking:'.$b->id);
                if ($customer !== null) {
                    $todayBookedCustomerIds[(int) $customer['id']] = true;
                }
                if ($b->is_trial) {
                    $bookingTrialCount++;
                }
            }
        }

        // ---- 待续费：待续课清单（临近到期优先） ----
        // 明细与清单同源：memberListWatch() 与 memberListIds() 来自同一次缓存扫描，
        // 因此「为什么他在清单里」与「他在不在清单里」永远自洽（D3/§5.4）。
        $renewalWatch = memberListWatch()['待续费'] ?? [];
        $renewals = [];
        if (! $isMedia) {
            $renewals = $scopedCustomers
                ->filter(fn ($c) => in_array('待续课', $listMap[$c['id']] ?? [], true)
                    && isset($todayBookedCustomerIds[(int) $c['id']]))
                ->map(function ($c) use ($daysBetween, $listMap, $doneInfo, $renewalWatch) {
                    $expireDays = $daysBetween($c['expireDate']);
                    $meta = $renewalWatch[$c['id']] ?? [];
                    // 到期日状态：current（允许「X 天后到期 / 今日到期」）、history（只说「已过期 N 天」）、
                    // none（显示 —）。前端「今日到期」文案必须读它，而不是 `expireDays <= 0`（D08-①）。
                    $expireDateState = $meta['expireDateState'] ?? match (true) {
                        $c['expireDate'] === null => 'none',
                        ($expireDays ?? 0) < 0 => 'history',
                        default => 'current',
                    };

                    return [
                        'id' => $c['id'],
                        'key' => 'renewal:'.$c['id'],
                        'name' => $c['name'],
                        'phone' => (string) ($c['phone'] ?? ''),
                        'phoneTail' => $c['phoneTail'],
                        'venue' => $c['venue'],
                        'mainCard' => $c['mainCard'],
                        'remainTimes' => $c['remainTimes'],
                        'expireDate' => $c['expireDate'],
                        'expireDays' => $expireDays,
                        // 紧急度以判定层为准（in ∧ (m3>0 ∨ deadline 命中)），不再用「7 天内到期」重算：
                        // 历史卡的负天数曾被当成「今天到期」，正是 D08 的误判来源。
                        'urgent' => (bool) ($meta['urgent'] ?? false),
                        'owner' => $c['owner'],
                        'consultant' => $c['consultant'] ?? '',
                        'evalLevel' => $c['evalLevel'] ?? null,
                        'hasRenewalPlan' => ! empty($c['renewalPlan']),
                        'lists' => $listMap[$c['id']] ?? [],
                        // ── v3.9 判定依据（与 renewalReasons / 规范 §5.2 同构）──────
                        'expireDateState' => $expireDateState,
                        'nextExpireDays' => $meta['nextExpireDays'] ?? $expireDays,
                        'mainCardState' => $meta['mainCardState'] ?? 'none',
                        'usableCount' => $meta['usableCount'] ?? null,
                        'displayCount' => $meta['displayCount'] ?? null,
                        'why' => $meta['why'] ?? [],
                        'whyCodes' => $meta['whyCodes'] ?? [],
                        'hits' => $meta['hits'] ?? [],
                        'entitlement' => $c['cardStats']['entitlement'] ?? null,
                    ] + $doneInfo('renewal:'.$c['id']);
                })
                ->sortBy(fn ($c) => [$c['expireDays'] === null ? 9999 : $c['expireDays'], $c['remainTimes'] ?? 999])
                ->values()->all();
        }

        // ---- 可能流失：预流失 + 待复活 + 出勤降低 ----
        $churnRisks = [];
        if (! $isMedia) {
            $riskSets = ['预流失', '待复活', '出勤降低'];
            $churnRisks = $scopedCustomers
                ->filter(fn ($c) => collect($riskSets)->contains(fn ($k) => in_array($k, $listMap[$c['id']] ?? [], true)))
                ->map(function ($c) use ($listMap, $doneInfo) {
                    $lists = array_values(array_intersect($listMap[$c['id']] ?? [], ['预流失', '待复活', '出勤降低']));
                    $lastVisitDays = $c['lastVisit'] ? abs((int) ((time() - strtotime((string) $c['lastVisit'])) / 86400)) : null;

                    return [
                        'id' => $c['id'],
                        'key' => 'churn:'.$c['id'],
                        'name' => $c['name'],
                        'phone' => (string) ($c['phone'] ?? ''),
                        'phoneTail' => $c['phoneTail'],
                        'venue' => $c['venue'],
                        'lists' => $lists,
                        'lastVisit' => $c['lastVisit'],
                        'lastVisitDays' => $lastVisitDays,
                        'stopReason' => $c['stopReason'],
                        'expectedReturn' => $c['expectedReturn'],
                        'needsHelp' => (bool) $c['needsHelp'],
                        'evalLevel' => $c['evalLevel'] ?? null,
                        'owner' => $c['owner'],
                        'consultant' => $c['consultant'] ?? '',
                    ] + $doneInfo('churn:'.$c['id']);
                })
                ->sortByDesc(fn ($c) => $c['lastVisitDays'] ?? 999)
                ->values()->all();
        }

        // ---- 生日关怀：今日 + 未来7天 ----
        $birthdays = [];
        if (! $isMedia) {
            $start = $today->copy();
            $window = [];
            for ($i = 0; $i <= 7; $i++) {
                $window[] = $start->copy()->addDays($i)->format('m-d');
            }
            $birthdays = $scopedCustomers
                ->filter(fn ($c) => ! empty($c['birthday']) && in_array(substr((string) $c['birthday'], 5, 5), $window, true))
                ->map(function ($c) use ($today, $listMap, $doneInfo) {
                    $md = substr((string) $c['birthday'], 5, 5);
                    $offset = (int) ((strtotime(date('Y').'-'.$md) - strtotime($today->format('Y-m-d'))) / 86400);
                    $offset = $offset < 0 ? $offset + 366 : $offset; // 跨年兜底（2/29）

                    return [
                        'id' => $c['id'],
                        'key' => 'birthday:'.$c['id'],
                        'name' => $c['name'],
                        'phone' => (string) ($c['phone'] ?? ''),
                        'phoneTail' => $c['phoneTail'],
                        'venue' => $c['venue'],
                        'birthday' => $c['birthday'],
                        'isToday' => $offset === 0,
                        'daysLater' => $offset,
                        'age' => (int) date('Y') - (int) substr((string) $c['birthday'], 0, 4),
                        'owner' => $c['owner'],
                        'consultant' => $c['consultant'] ?? '',
                        'lists' => $listMap[$c['id']] ?? [],
                    ] + $doneInfo('birthday:'.$c['id']);
                })
                ->sortBy(fn ($c) => [$c['isToday'] ? 0 : 1, $c['daysLater']])
                ->values()->all();
        }

        // ---- 今日体验课：随心瑜体验预约事实 + 留资体验课卡片 ----
        $trials = [];
        if (! $isMedia) {
            $trialQ = KyBooking::query()
                ->whereBetween('start_at', [$today->copy(), $today->copy()->endOfDay()])
                ->where('is_trial', true)
                ->whereNotIn('status', ['cancelled', 'no_show']);
            if (! $isSuper) {
                $trialQ->where('venue', $u->venue);
            }
            self::scopeBookingsToUser($trialQ, $u);
            foreach ($trialQ->orderBy('start_at')->get() as $b) {
                $digits = preg_replace('/\D+/', '', (string) $b->phone) ?? '';
                $trials[] = [
                    'key' => 'trial:ky-'.$b->id,
                    'time' => $b->start_at?->format('H:i'),
                    'name' => (string) $b->member_name,
                    'phone' => $digits,
                    'phoneTail' => $digits !== '' ? substr($digits, -4) : '',
                    'venue' => (string) $b->venue,
                    'topic' => (string) $b->course_name,
                    'teacher' => (string) $b->teacher_name,
                    'source' => 'ky',
                    'status' => (string) $b->status,
                    // KY 预约无直接留资，处理时后端按手机号唯一命中反查（见 todoAction 业务流转 3）
                    'leadId' => null,
                    'session' => null,
                    // 已建档会员才能直达详情页：严格匹配（memberId / 归一手机号），
                    // 认不出就给 null —— 前端据此落到会员列表，绝不按姓名猜（见 $matchCustomerStrict）
                    'customerId' => ($m = $matchCustomerStrict($digits, (string) $b->member_id)) ? (int) $m['id'] : null,
                ] + $doneInfo('trial:ky-'.$b->id);
            }
        }
        $leadQ = scopeLeadsForUser(Lead::query(), $u);
        $newLeads = [];
        foreach ($leadQ->get() as $l) {
            $arr = camel($l);
            // 留资体验课卡片中的今日体验（已取消的卡片不再进入待办）
            foreach ((array) ($l->trial_cards ?? []) as $card) {
                if (! empty($card['cancelled'])) {
                    continue;
                }
                $cardDate = substr((string) ($card['time'] ?? ''), 0, 10);
                if ($cardDate === $today->format('Y-m-d')) {
                    // session 由 Lead 模型的 trialCards 访问器保证存在（缺则按数组位置补），
                    // 所以这里直接取整 —— 不要再写 `?? ''` 那种兜底：key 少一节会拼成
                    // `trial:lead-6-`，todoAction 里 $session 解析为 0，卡片回写被整段跳过。
                    $session = (int) $card['session'];
                    $trialKey = 'trial:lead-'.$l->id.'-'.$session;
                    $trials[] = [
                        'key' => $trialKey,
                        'time' => mb_substr((string) ($card['time'] ?? ''), 11, 5) ?: (string) ($card['time'] ?? ''),
                        'name' => (string) $l->name,
                        'phone' => (string) $l->phone,
                        'phoneTail' => substr((string) $l->phone, -4),
                        'venue' => (string) $l->venue,
                        'topic' => (string) ($card['topic'] ?? ''),
                        'teacher' => (string) ($card['teacher'] ?? ''),
                        'source' => 'lead',
                        'status' => (string) $l->status,
                        // 携带留资定位，处理时回写留资状态机与体验课卡片（见 todoAction 业务流转 3）
                        'leadId' => (int) $l->id,
                        'session' => $session,
                        // 与 KY 分支保持同一组键（否则前端得按 source 判字段存在性）；
                        // 留资行没有 member_id，只能按手机号严格命中已建档会员，认不出给 null。
                        'customerId' => ($m = $matchCustomerStrict((string) $l->phone, '')) ? (int) $m['id'] : null,
                    ] + $doneInfo($trialKey);
                }
            }
            if ($arr['status'] === '新留资') {
                $newLeads[] = [
                    'id' => $l->id,
                    'key' => 'lead:'.$l->id,
                    'name' => (string) $l->name,
                    'phone' => (string) $l->phone,
                    'phoneTail' => substr((string) $l->phone, -4),
                    'venue' => (string) $l->venue,
                    'source' => (string) $l->source,
                    'demand' => (string) $l->demand,
                    'grade' => (string) $l->grade,
                    'serviceTeacher' => (string) $l->service_teacher,
                    'leadDate' => (string) $l->lead_date,
                    'stale' => $l->created_at && $l->created_at->diffInHours(now()) >= 24,
                    // 留资距今时长（前端「今日三件事」聚合卡用它显示紧迫度）
                    'createdAt' => $l->created_at?->toIso8601String(),
                    'remark' => (string) $l->remark,
                ] + $doneInfo('lead:'.$l->id);
            }
        }
        $newLeads = collect($newLeads)
            ->sortByDesc(fn ($l) => [$l['stale'] ? 1 : 0, $l['id']])
            ->values()->all();
        usort($trials, fn ($a, $b) => strcmp((string) $a['time'], (string) $b['time']));

        // ---- 待填写课后分析：今天已签到的体验课与私教课（服务老师不排训练，不参与） ----
        $reviews = [];
        if (! $isMedia && ! userHasRole($u, 'R_SERVICE')) {
            $reviewQ = KyBooking::query()
                ->whereBetween('start_at', [$today->copy(), $today->copy()->endOfDay()])
                ->where('status', 'signed')
                ->where(fn ($w) => $w->where('is_trial', true)->orWhere('course_kind', 'private'));
            self::scopeBookingsToUser($reviewQ, $u);
            if (! $isSuper) {
                $reviewQ->where('venue', $u->venue);
            }
            // 同一时段的课不少，加 id 兜底让列表顺序稳定
            $rows = $reviewQ->orderBy('start_at')->orderBy('id')->get();
            $reviewed = PostClassReview::whereIn('booking_id', $rows->pluck('id')->all() ?: [-1])
                ->pluck('booking_id')->all();
            foreach ($rows as $b) {
                if (in_array($b->id, $reviewed, true)) {
                    continue;
                }
                $digits = preg_replace('/\D+/', '', (string) $b->phone) ?? '';
                $reviews[] = [
                    'key' => 'review:ky-'.$b->id,
                    'bookingId' => $b->id,
                    // 只给 bookingId 时前端只能落到「课后分析列表」让员工自己找；
                    // 补 customerId 后可直达会员详情。严格匹配（memberId / 归一手机号），认不出给 null。
                    'customerId' => ($m = $matchCustomerStrict($digits, (string) $b->member_id)) ? (int) $m['id'] : null,
                    'time' => $b->start_at?->format('H:i'),
                    'name' => (string) $b->member_name,
                    'phone' => $digits,
                    'phoneTail' => $digits !== '' ? substr($digits, -4) : '',
                    'venue' => (string) $b->venue,
                    'course' => (string) $b->course_name,
                    'teacher' => (string) $b->teacher_name,
                    'kind' => KyBooking::KIND_LABELS[$b->courseKind()] ?? '团课',
                    'isTrial' => (bool) $b->is_trial,
                ] + $doneInfo('review:ky-'.$b->id);
            }
        }

        // ---- 今日任务：今天到期或逾期 ----
        // 可见范围收敛到 scopeTasksForUser（唯一收口点）。本处此前只有「非超管卡本店」这一层，
        // 角色不明账号的 venue 恰好等于本人门店时就会看到本店他人任务（t15 残留项①）。
        $taskQ = scopeTasksForUser(
            Task::query()
                ->whereNotIn('status', ['已完成'])
                ->where('deadline', '!=', '')
                ->where('deadline', '<=', $today->format('Y-m-d 23:59')),
            $u,
            'todo'
        );
        $tasks = collect($taskQ->orderBy('deadline')->get())->map(fn ($t) => camel($t))
            ->map(fn ($t) => $t + ['overdue' => (string) $t['deadline'] < $today->format('Y-m-d 00:00') || $t['status'] === '已逾期'])
            ->all();

        return ok([
            'date' => $today->format('Y-m-d'),
            // 返回当前生效的清单阈值（客户管理可调），工作台展示并与会员管理口径保持一致
            'rules' => rules(),
            'bookings' => ['items' => $bookings, 'trialCount' => $bookingTrialCount],
            'renewals' => $renewals,
            'churnRisks' => $churnRisks,
            'birthdays' => $birthdays,
            'trials' => $trials,
            'reviews' => $reviews,
            'newLeads' => $newLeads,
            'tasks' => $tasks,
            'counts' => [
                'bookings' => count($bookings),
                'renewals' => count($renewals),
                'churnRisks' => count($churnRisks),
                'birthdays' => count($birthdays),
                'trials' => count($trials),
                'reviews' => count($reviews),
                'newLeads' => count($newLeads),
                'tasks' => count($tasks),
            ],
            'generatedAt' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    /** POST /today/todo/action */
    public function todoAction(Request $r)
    {
        $u = $r->user();
        $d = $r->validate([
            'type' => 'required|in:bookings,renewals,churnRisks,birthdays,trials,reviews,newLeads',
            'key' => 'required|string|max:80',
            'action' => 'required|string|max:30',
            'remark' => 'nullable|string|max:200',
            'customerId' => 'nullable|integer',
            'leadId' => 'nullable|integer',
            // renewals/churnRisks 标记沟通时同步更新会员最近触达
            'touch' => 'nullable|boolean',
        ]);
        $today = now()->toDateString();
        $typeLabels = [
            'bookings' => '今日预约', 'renewals' => '待续费', 'churnRisks' => '流失风险',
            'birthdays' => '生日关怀', 'trials' => '体验课', 'reviews' => '课后分析',
            'newLeads' => '新客首响',
        ];

        DB::transaction(function () use ($r, $u, $d, $today, $typeLabels) {
            $flowNote = '';
            // 业务流转 1：新客首响 → 留资状态机（已首响→已联系、无效客资→已流失）
            if ($d['type'] === 'newLeads' && ! empty($d['leadId'])) {
                $lead = Lead::find($d['leadId']);
                if ($lead) {
                    // 判据统一走 userCanAccessLead()（helpers.php，中间件同一份）：
                    // 待承接池已下线，未分配客资不再允许老师侧从待办里直接标记 ——
                    // 否则「列表里看不到、待办/直达 URL 却能把状态改掉」。
                    $canHandle = userCanAccessLead($u, $lead);
                    abort_unless($canHandle, 403, '无权处理该客资');
                    $next = match ($d['action']) {
                        '已首响' => '已联系',
                        '无效客资' => '已流失',
                        default => null,
                    };
                    if ($next !== null && (string) $lead->status !== $next) {
                        $lead->update(['status' => $next]);
                        audit($r, '标记处理', '前端客资', $lead->id, "{$lead->name}（{$lead->source}）", $lead->venue, "今日待办标记：状态变更为 {$next}");
                    }
                }
            }

            // 业务流转 3：体验课接待/爽约 → 留资状态机 + 体验课卡片结果
            // 爽约不清空跟进时限（时限仅随体验课卡片「已取消」留白），客户爽约后仍需跟进促改约
            if ($d['type'] === 'trials') {
                $lead = null;
                $session = 0;
                $enforceLeadPermission = true;
                if (preg_match('/^trial:lead-(\d+)-(\d*)$/', $d['key'], $m)) {
                    // 留资体验课卡片：key 直接携带留资 ID 与节次
                    $lead = Lead::find((int) $m[1]);
                    $session = (int) ($m[2] ?? 0);
                } elseif (preg_match('/^trial:ky-(\d+)$/', $d['key'], $m)) {
                    // 随心瑜体验预约：按手机号反查留资，仅「唯一命中一条进行中留资」时联动，
                    // 多命中视为歧义跳过（避免把状态写到重复留资/他人留资上），当日标记不受影响
                    $booking = KyBooking::find((int) $m[1]);
                    $digits = preg_replace('/\D+/', '', (string) ($booking->phone ?? '')) ?? '';
                    if ($digits !== '') {
                        $candidates = Lead::where('phone', $digits)
                            ->whereNotIn('status', ['已成交', '已流失'])
                            ->orderByDesc('id')->get();
                        if ($candidates->count() === 1) {
                            $lead = $candidates->first();
                            $enforceLeadPermission = false;
                        } elseif ($candidates->count() > 1) {
                            $flowNote = '；同手机号命中多条进行中留资，未联动状态';
                        }
                    }
                } elseif (! empty($d['leadId'])) {
                    $lead = Lead::find((int) $d['leadId']);
                    if (preg_match('/(\d+)$/', $d['key'], $m)) {
                        $session = (int) $m[1];
                    }
                }
                if ($lead) {
                    // 判据统一走 userCanAccessLead()（helpers.php，中间件同一份）：
                    // 待承接池已下线，未分配客资不再允许老师侧从待办里直接标记 ——
                    // 否则「列表里看不到、待办/直达 URL 却能把状态改掉」。
                    $canHandle = userCanAccessLead($u, $lead);
                    if ($enforceLeadPermission) {
                        abort_unless($canHandle, 403, '无权处理该客资');
                    }
                    if ($canHandle) {
                        $next = match ($d['action']) {
                            '已接待' => '已体验',
                            '爽约' => '爽约',
                            default => null,
                        };
                        if ($next !== null && ! in_array((string) $lead->status, ['已成交', '已流失'], true) && (string) $lead->status !== $next) {
                            $lead->update(['status' => $next]);
                            audit($r, '标记处理', '前端客资', $lead->id, "{$lead->name}（{$lead->source}）", $lead->venue, "今日待办体验课标记 {$d['action']}：状态变更为 {$next}");
                        }
                        // 卡片结果标记：仅明确到节的留资体验课回写（已接待=已上课、爽约=已爽约）
                        if ($session > 0 && in_array($d['action'], ['已接待', '爽约'], true)) {
                            // $cards 走 Lead 的 trialCards 访问器，每张都带 session（缺则按位置补），
                            // 所以这里按 session 精确匹配即可 —— 原来那句 `?? ($i + 1)` 与待办 key
                            // 生成侧的 `?? ''` 口径相反，已随访问器落地一并删除（同一缺陷的两个矛盾兜底）。
                            // 顺带：回写会把补好的 session 一起落库，历史卡片渐进自愈。
                            $cards = (array) ($lead->trial_cards ?? []);
                            foreach ($cards as $i => $card) {
                                if ((int) $card['session'] === $session) {
                                    if ($d['action'] === '爽约') {
                                        $cards[$i]['noShow'] = true;
                                    } else {
                                        $cards[$i]['attended'] = true;
                                        $cards[$i]['noShow'] = false;
                                    }
                                    $lead->update(['trial_cards' => $cards]);
                                    break;
                                }
                            }
                        }
                    }
                }
            }

            // 业务流转 2：续费/流失沟通 → 更新会员最近触达，保证 2 周触达口径不断档
            if (! empty($d['customerId']) && ! empty($d['touch'])) {
                $c = Customer::find($d['customerId']);
                if ($c) {
                    abort_unless(canAccessCustomer($u, $c), 403, '无权处理该会员');
                    $c->update(['last_touch' => $today]);
                }
            }

            TodoAction::updateOrCreate(
                ['todo_key' => $d['key'], 'action_date' => $today],
                [
                    'todo_type' => $d['type'],
                    'action' => $d['action'],
                    'remark' => (string) ($d['remark'] ?? ''),
                    'user_id' => $u->id,
                    'user_name' => $u->name,
                    'user_role' => primaryRole(userRoles($u)),
                    'venue' => (string) ($u->venue ?? ''),
                ]
            );
            invalidateBusinessCaches();
            audit($r, '标记处理', '今日待办', $d['key'], $typeLabels[$d['type']], (string) ($u->venue ?? '双店'), $d['action'].((string) ($d['remark'] ?? '') !== '' ? '：'.$d['remark'] : '').$flowNote);
        });

        return ok(['done' => true]);
    }
}
