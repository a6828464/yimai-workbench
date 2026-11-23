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
use App\Support\CardLifecycle;
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
            // 与 GET /customers 同一条口径：**列表不带会员备注**。列表页不渲染它，带上只是无谓负载；
            // 而且备注可能含健康/隐私信息（「腰有旧伤…」这类），能少发一处就少发一处。
            // 备注只在详情 GET /customers/{id} 返回。
            unset($arr['remark']);
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

        /*
         * ════════════════════════════════════════════════════════════════════
         * v3.19.0 六组卡片「信息增强」的公共口径（**纯新增**：既有键一个不动）
         * ════════════════════════════════════════════════════════════════════
         * 为什么要有这一块：真机反馈「待办里只有名字和时间，要判断这人该不该催、
         * 卡还剩几次、多久没来，得一路跳进会员详情」。所以每组补一句能直接下判断的话。
         *
         * 三条硬口径，改之前先读：
         *  1. **卡名空值归一**：`customers.main_card` 的建表默认值是 `'—'`，同步没跑过时
         *     写入的是 `'待同步卡项'`。这两个都不是卡名，必须归一成空串 —— 否则前端会渲染出
         *     「主卡 — · 剩 3 节」这种自相矛盾的提示。
         *  2. **bookings / churnRisks 的剩余量只信 `customers.remain_times`**（主卡口径，与
         *     `/today/alerts`、会员列表读的是同一列），**不在这里重算**。重算就会出现
         *     「待办说剩 3 节、会员详情说剩 2 节」的同源分裂，是最难被发现的缺陷形态。
         *     （唯一的例外是 renewals：它在下方单独说明了为什么取**逐卡**的值 ——
         *     那一组的语义就是「最近到期/余额最少的那张卡」，取汇总列会指错卡。）
         *  3. **到期天数统一走 `CardLifecycle::daysUntilDate()`**（判定层唯一日期口径），
         *     不自己写 strtotime 差值：跨年、时区、`Ymd` 与时间戳混存这些坑它都处理过了。
         *
         * 所有数据都取自**已经加载的** `$scopedCustomers` / `$listMap` / 本轮既有查询，
         * 不为这些字段新增任何逐行 SQL。
         */
        $cardTitle = function ($value): string {
            $title = trim((string) $value);

            return in_array($title, ['', '—', '-', '待同步卡项'], true) ? '' : $title;
        };
        /**
         * 「剩 N 单位」片段。**期限卡（unit=天）一律返回空串**（t4-F1）。
         *
         * 为什么：期限卡的 `remaining` 就是「距到期天数」（`CardLifecycle::normalize()` 里由
         * deadline 推导），与卡片下面要拼的「X 天后到期」**恒等** —— 再拼一次就成了
         * 「年卡 · 剩 7 天 · 7 天后到期」，同一个数字说两遍。年卡会员正是待续费主力人群，
         * 这行在真机上会反复出现。
         *
         * 为什么不做成「共 365 天」：总量要么来自 `boundTotal`（会员详情侧的派生字段，
         * 这里没有加载，硬拼就要为一行文案加查询），要么就得容忍「共 null 天」——
         * 后者是比重复更难看的缺陷。所以取简单解：期限卡只说到期。
         * 次卡（节）/储值卡（元）的剩余量与到期日是两件事，行为保持不变。
         */
        $cardQty = function ($remaining, string $unit = '节'): string {
            if ($unit === '天') {
                return '';
            }

            return $remaining === null || $remaining === '' ? '' : '剩 '.$remaining.' '.$unit;
        };
        $expireText = function (?int $days): string {
            if ($days === null) {
                return '';
            }
            if ($days < 0) {
                return '已过期 '.abs($days).' 天';
            }

            return $days === 0 ? '今天到期' : $days.' 天后到期';
        };
        // bookings / churnRisks 共用：主卡标题 + 主卡剩余（同一次读，避免两处各写一遍）
        $memberCardBrief = function (?array $c) use ($cardTitle, $cardQty): array {
            $title = $c === null ? '' : $cardTitle($c['mainCard'] ?? '');
            $remain = ($c === null || ! is_numeric($c['remainTimes'] ?? null)) ? null : (int) $c['remainTimes'];
            $qty = $cardQty($remain);
            $hint = $title === '' ? '' : ($qty === '' ? $title : $title.' · '.$qty);

            return ['mainCard' => $title, 'remainTimes' => $remain, 'cardHint' => $hint];
        };
        /*
         * renewals.cardName：读的是**待续费判定同一份输入** `customers.cards_list`（真源），
         * 取其中的在用卡（state=current），按「最近到期 → 余额最少」升序取第一张。
         * 无在用卡时回退主卡标题 —— 契约里点名了这个回退，不要自己改成「—」。
         * daysLeft 优先（同步侧已算好），旧快照没有它时用 CardLifecycle 从 deadline 反推。
         */
        $renewalCardOf = function (array $c) use ($cardTitle): array {
            $pick = [];
            foreach ((array) ($c['cardsList'] ?? []) as $card) {
                if (! is_array($card) || (string) ($card['state'] ?? 'current') !== 'current') {
                    continue;
                }
                $pick[] = [
                    'title' => $cardTitle($card['title'] ?? ''),
                    'days' => is_numeric($card['daysLeft'] ?? null)
                        ? (int) $card['daysLeft']
                        : CardLifecycle::daysUntilDate($card['deadline'] ?? null),
                    'remaining' => is_numeric($card['residue'] ?? null) ? (int) $card['residue'] : null,
                    'unit' => (string) ($card['unit'] ?? '节'),
                ];
            }
            if ($pick !== []) {
                usort($pick, function (array $a, array $b): int {
                    return [$a['days'] ?? PHP_INT_MAX, $a['remaining'] ?? PHP_INT_MAX]
                        <=> [$b['days'] ?? PHP_INT_MAX, $b['remaining'] ?? PHP_INT_MAX];
                });

                return $pick[0];
            }

            return [
                'title' => $cardTitle($c['mainCard'] ?? ''),
                'days' => CardLifecycle::daysUntilDate($c['expireDate'] ?? null),
                'remaining' => is_numeric($c['remainTimes'] ?? null) ? (int) $c['remainTimes'] : null,
                'unit' => '节',
            ];
        };
        // 留资体验卡本身没有课型字段：卡片带了（courseKind / kind）才认，认不出给空串，绝不猜
        $trialCardKind = function (array $card): string {
            $raw = (string) ($card['courseKind'] ?? $card['kind'] ?? '');
            if (isset(KyBooking::KIND_LABELS[$raw])) {
                return KyBooking::KIND_LABELS[$raw];
            }

            return in_array($raw, KyBooking::KIND_LABELS, true) ? $raw : '';
        };
        // 新客首响的「为什么在这里」：来源 + 停留时长 + 未首响（例：「大众点评留资 2 小时未首响」）
        $leadWhy = function (Lead $l): string {
            $source = trim((string) $l->source) ?: '客资';
            if (! $l->created_at) {
                return $source.'留资待首响';
            }
            $hours = (int) $l->created_at->diffInHours(now());
            if ($hours < 1) {
                return $source.'刚留资，待首响';
            }
            $waited = $hours >= 24 ? intdiv($hours, 24).' 天' : $hours.' 小时';

            return $source.'留资 '.$waited.'未首响';
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
                    // ── v3.19.0 卡片信息增强（纯新增）──────────────────────────
                    // attend/attendKnown：近三个 30 天出勤，与会员列表（attendM1/M2/M3）同源同序。
                    // 没匹配到会员档案时给全 0 且 attendKnown=false —— 前端据此显示「无档案」，
                    // 而不是把「查不到人」误报成「近三月没来」（两种结论的动作完全相反）。
                    'attend' => [
                        'm1' => (int) ($customer['attendM1'] ?? 0),
                        'm2' => (int) ($customer['attendM2'] ?? 0),
                        'm3' => (int) ($customer['attendM3'] ?? 0),
                    ],
                    'attendKnown' => $customer !== null,
                ] + $memberCardBrief($customer) + $doneInfo('booking:'.$b->id);
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
                ->map(function ($c) use ($daysBetween, $listMap, $doneInfo, $renewalWatch, $renewalCardOf, $cardQty, $expireText) {
                    $expireDays = $daysBetween($c['expireDate']);
                    $meta = $renewalWatch[$c['id']] ?? [];
                    /*
                     * v3.19.0：卡片上补「哪张卡 + 剩多少 + 多久到期」。
                     * 卡取 cardsList 里「最近到期 / 余额最少」的那张在用卡（无则回退主卡），
                     * 剩余量与到期天数**优先用这张卡自己的值**（与会员详情逐卡展示一致）；
                     * 拿不到卡（老数据只有汇总列）时才退回 customers.remain_times / expireDays。
                     */
                    $renewalCard = $renewalCardOf($c);
                    $cardDays = $renewalCard['days'] ?? $expireDays;
                    $cardQtyText = $cardQty($renewalCard['remaining'], $renewalCard['unit']);
                    $cardExpText = $expireText($cardDays);
                    $cardHint = implode(' · ', array_values(array_filter(
                        [$renewalCard['title'], $cardQtyText, $cardExpText],
                        fn ($part) => $part !== ''
                    )));
                    // 「私教小班剩 3 节，7 天后到期」：没有卡名时只说到期，别输出一句空主语
                    $whyHead = $renewalCard['title'].$cardQtyText;
                    $whyText = match (true) {
                        $whyHead !== '' && $cardExpText !== '' => $whyHead.'，'.$cardExpText,
                        $whyHead !== '' => $whyHead,
                        $cardExpText !== '' => $cardExpText,
                        default => '待续课清单命中',
                    };
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
                        // ── v3.19.0 卡片信息增强（纯新增；上面的 mainCard / remainTimes
                        //    仍是 customers 汇总列，语义与类型一字未动）──────────────
                        'cardName' => $renewalCard['title'],
                        'cardHint' => $cardHint,
                        'whyText' => $whyText,
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
                ->map(function ($c) use ($listMap, $doneInfo, $memberCardBrief) {
                    $lists = array_values(array_intersect($listMap[$c['id']] ?? [], ['预流失', '待复活', '出勤降低']));
                    $lastVisitDays = $c['lastVisit'] ? abs((int) ((time() - strtotime((string) $c['lastVisit'])) / 86400)) : null;
                    /*
                     * v3.19.0：「为什么算流失」一句话说全 —— 多久没到店（上次哪天）+ 卡的状态。
                     * 无到店记录时显式写「无到店记录」，不写「0 天没到店」（那会被读成今天刚来过）。
                     * 卡段在无卡时整段省略（不留「卡：」这种空壳）。
                     */
                    $brief = $memberCardBrief($c);
                    /*
                     * 到期日与会员详情**同一份实现**（`CardLifecycle::displayExpireDate`，t4-F4）：
                     * 库里有值优先，为空则从卡项推导。此前这里直接读原始列，于是同一个会员
                     * 在流失风险卡片上说「无到期日」、在会员详情上说「2026-08-01（按卡项推断）」，
                     * 两套答案。推导口径只允许有一份实现，禁止在这里复制第二份。
                     */
                    [$churnExpireDate] = CardLifecycle::displayExpireDate(
                        isset($c['expireDate']) ? (string) $c['expireDate'] : null,
                        (array) ($c['cardsList'] ?? [])
                    );
                    $visitText = $lastVisitDays === null
                        ? '无到店记录'
                        : $lastVisitDays.' 天没到店'.($c['lastVisit'] ? '（上次 '.date('n-j', strtotime((string) $c['lastVisit'])).'）' : '');
                    $cardSeg = $brief['mainCard'] === '' ? '' : trim(
                        $brief['mainCard'].($brief['remainTimes'] === null ? '' : ' 剩 '.$brief['remainTimes'].' 节')
                    );

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
                        // ── v3.19.0 卡片信息增强（纯新增）──────────────────────
                        'mainCard' => $brief['mainCard'],
                        'remainTimes' => $brief['remainTimes'],
                        // 主卡到期日（口径见上方 $churnExpireDate）：只裁成 Y-m-d；
                        // 空值给 null（≠ ''，前端据此决定是否显示这一行）。
                        'expireDate' => $churnExpireDate === null ? null : substr($churnExpireDate, 0, 10),
                        'whyText' => $visitText.($cardSeg === '' ? '' : '· 卡：'.$cardSeg),
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
                    // v3.19.0：生日卡片补「MM-DD」「N 岁」两个直读文案。
                    // 年龄口径与既有 age 键完全一致（当前年份 − 出生年份），不另算一套；
                    // 生日缺失（理论上进不了本组）给空串而不是「0 岁」——0 岁是把「未知」说成了事实。
                    $birthdayRaw = trim((string) ($c['birthday'] ?? ''));

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
                        // ── v3.19.0 卡片信息增强（纯新增）──────────────────────
                        'birthdayText' => $birthdayRaw === '' ? '' : substr($birthdayRaw, 5, 5),
                        'ageText' => $birthdayRaw === '' ? '' : ((int) date('Y') - (int) substr($birthdayRaw, 0, 4)).' 岁',
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
            $kyTrials = $trialQ->orderBy('start_at')->get();
            /*
             * v3.19.0：KY 体验课条目补 leadId（此前恒为 null，前端不知道有没有留资可联动）。
             *
             * 口径与 todoAction 业务流转 3 **逐字一致**：手机号精确匹配 → 排除终态
             * （已成交 / 已流失）→ **只有唯一命中**才给 id；同号多条进行中留资视为歧义给 null
             * （宁可前端不做联动，也绝不把结果写到别人的留资上）。
             *
             * 一次 `whereIn` 批量反查，不是逐行查：待办页每次都要跑，逐行查会变成 N 次 SQL。
             */
            $kyTrialLeadIds = $this->pendingLeadIdsByPhone($kyTrials->pluck('phone')->all());
            foreach ($kyTrials as $b) {
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
                    // KY 预约无直接留资，处理时后端按手机号唯一命中反查（见 todoAction 业务流转 3）；
                    // 上面那次批量反查给出「唯一命中的进行中留资」，多命中/无命中仍是 null。
                    'leadId' => $kyTrialLeadIds[$digits] ?? null,
                    'session' => null,
                    // 已建档会员才能直达详情页：严格匹配（memberId / 归一手机号），
                    // 认不出就给 null —— 前端据此落到会员列表，绝不按姓名猜（见 $matchCustomerStrict）
                    'customerId' => ($m = $matchCustomerStrict($digits, (string) $b->member_id)) ? (int) $m['id'] : null,
                    // ── v3.19.0 卡片信息增强（纯新增）──────────────────────────
                    // course 与既有 topic 同值：契约要求两种卡片共用同一组键名，
                    // 留资卡片的 course 也是 topic，前端一套渲染即可。
                    'course' => (string) $b->course_name,
                    'kind' => KyBooking::KIND_LABELS[$b->courseKind()] ?? '团课',
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
                        // ── v3.19.0 卡片信息增强（纯新增；leadId/session 语义不动）────
                        'course' => (string) ($card['topic'] ?? ''),
                        'kind' => $trialCardKind($card),
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
                    // ── v3.19.0 卡片信息增强（纯新增）：一句「为什么在这里」──────
                    'whyText' => $leadWhy($l),
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
                $reviewKind = KyBooking::KIND_LABELS[$b->courseKind()] ?? '团课';
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
                    'kind' => $reviewKind,
                    'isTrial' => (bool) $b->is_trial,
                    // ── v3.19.0 卡片信息增强（纯新增）：为什么出现在这里 ─────────
                    // 本组只装「今天已签到、还没写分析」的课，所以那句话是确定的，不需要额外查库。
                    // 课型本身已经带「课」字（`团课`）时不能再拼一个：否则出现「今日团课课已签到」
                    // 这种一眼是机器写的中文（t2 前端联调发现）。`私教`/`小班` 仍补「课」，
                    // 读起来才是「今日私教课」「今日小班课」。
                    'whyText' => ($b->is_trial ? '今日体验课' : '今日'.$reviewKind.(str_ends_with($reviewKind, '课') ? '' : '课')).'已签到，待填写课后分析',
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

    /**
     * 归一的手机号 → 「进行中留资」id（v3.19.0，供 trials 组补 leadId）。
     *
     * 口径与 todoAction 业务流转 3 **共用同一套实现**（`self::phoneForms()`）：
     * 手机号等价形态匹配 → 排除终态（已成交 / 已流失）→ **只有唯一命中**才给 id；
     * 同号多条进行中留资视为歧义给 null（宁可前端不做联动，也绝不把结果写到别人的留资上）。
     * 共用是硬要求：两份实现一旦分叉，就会出现「列表说有留资、点下去不动」的同源分裂。
     *
     * 为什么写成批量：待办页是小程序每次进工作台都会打的接口，
     * 逐行查会随当天体验课数量线性增加 SQL 次数。
     *
     * @param  array<int,string|null>  $phones  原始串（可能带 +86 / 分隔符）
     * @return array<string,int>
     */
    private function pendingLeadIdsByPhone(array $phones): array
    {
        $wanted = [];
        foreach ($phones as $phone) {
            $digits = normalizePhone(is_string($phone) ? $phone : (string) $phone);
            if ($digits !== '') {
                $wanted[$digits] = self::phoneForms($digits);
            }
        }
        if ($wanted === []) {
            return [];
        }

        $allForms = array_values(array_unique(array_merge(...array_values($wanted))));
        $rows = Lead::query()
            ->whereIn('phone', $allForms)
            ->whereNotIn('status', ['已成交', '已流失'])
            ->orderByDesc('id')
            ->get(['id', 'phone']);

        // 按「库里存的那个形态」归组，再按每条输入的反查形态集合取并集 ——
        // 这样 +86 / 86 前缀 / 长途 0 前缀三种写法都能命中同一条留资。
        $byForm = [];
        foreach ($rows as $row) {
            $byForm[normalizePhone((string) $row->phone)][] = (int) $row->id;
        }

        $out = [];
        foreach ($wanted as $digits => $forms) {
            $ids = [];
            foreach ($forms as $form) {
                foreach ($byForm[$form] ?? [] as $id) {
                    $ids[$id] = true;
                }
            }
            if (count($ids) === 1) {
                $out[$digits] = array_key_first($ids);
            }
        }

        return $out;
    }

    /**
     * 手机号「等价形态」候选集（v3.19.0）。
     *
     * 为什么需要它：`normalizePhone()` 只去掉分隔符，**不处理国家码与长途前缀**。
     * 而随心瑜预约的 `ky_bookings.phone` 里真实存在 `+8613800000002`（归一后是 13 位
     * `8613800000002`），留资表里同一个人是 11 位 `13800000002`；反过来手录留资也可能
     * 存成 `8613800000002`（`LeadController` 写入前同样只做 normalizePhone）。
     * 两边归一位数不同，`where('phone', $digits)` 就永远等不上 ——
     * 这是「点了已接待、留资管理状态没变」最大的一片命中缺口。
     *
     * 收敛口径：只做**等值**匹配的形态集合（11 位本体 / 86 前缀 / 去长途 0），
     * **不写 LIKE**：模糊匹配会把状态写到号码相近的另一个人身上，那是不可回滚的错误，
     * 比「不联动 + 一句原因」严重得多。
     *
     * 待办列表侧的 `leadId`（pendingLeadIdsByPhone）与 action 侧（todoAction 业务流转 3）
     * 必须共用本方法，否则会出现「列表说有留资可联动、点下去却不动」的同源分裂。
     *
     * @return array<int,string>
     */
    private static function phoneForms(?string $phone): array
    {
        $digits = normalizePhone($phone);
        if ($digits === '') {
            return [];
        }
        $forms = [$digits];
        if (strlen($digits) === 13 && str_starts_with($digits, '86')) {
            $forms[] = substr($digits, 2);
        } elseif (strlen($digits) === 12 && str_starts_with($digits, '0')) {
            $forms[] = substr($digits, 1);
        }
        // 反向补齐：输入是 11 位本体时，也要能命中库里存成 86 前缀的那一行
        $tail11 = strlen($digits) >= 11 ? substr($digits, -11) : '';
        if ($tail11 !== '' && str_starts_with($tail11, '1')) {
            $forms[] = $tail11;
            $forms[] = '86'.$tail11;
        }

        return array_values(array_unique(array_filter($forms, fn ($form) => $form !== '')));
    }

    /**
     * 把「已接待 / 爽约」落到 `leads.trial_cards`（v3.19.0，唯一回写口径），返回是否真的写了一节。
     *
     * 三种输入各有确定规则，**任何情况下都不猜**：
     *  1. `$session > 0`（key = `trial:lead-{id}-{session}`）：按节次精确匹配。
     *     `$cards` 走 Lead 的 trialCards 访问器，每张都带 session（缺则按数组位置补），
     *     所以这里只需要 `(int) $card['session'] === $session`；回写会把补好的 session
     *     一起落库，历史卡片渐进自愈。
     *  2. `$session <= 0`（key = `trial:ky-{bookingId}`，随心瑜预约没有节次）：**只有留资侧
     *     恰好存在一张「今天、未取消」的体验卡**时才回写。多于一张说明留资里有多节体验课，
     *     KY 这一行没有节次信息，猜一张就会把「第 2 节爽约」记到第 1 节上 ——
     *     卡片维度的不确定性不该用概率填，宁可让员工去留资管理里点。
     *  3. 一张卡都没有 / 卡片不在今天：不写，返回 false（由调用方把原因写进 flowNote）。
     *
     * 只改 `attended` / `noShow` 两个字段，不动 `cancelled`、`time`、`session` 等既有键；
     * 也不新增任何「影子状态」列 —— 卡片结果始终只存在 `leads.trial_cards` 里。
     */
    private function markTrialCardResult(Lead $lead, int $session, string $action, string $today): bool
    {
        $cards = (array) ($lead->trial_cards ?? []);
        if ($cards === []) {
            return false;
        }

        $targets = [];
        if ($session > 0) {
            foreach ($cards as $i => $card) {
                if ((int) ($card['session'] ?? 0) === $session) {
                    $targets[] = $i;
                    break;
                }
            }
        } else {
            foreach ($cards as $i => $card) {
                if (! empty($card['cancelled'])) {
                    continue;
                }
                if (substr((string) ($card['time'] ?? ''), 0, 10) === $today) {
                    $targets[] = $i;
                }
            }
            // 0 张 = 没有可回写的卡；≥2 张 = 无法确定是哪一节。两种都不写。
            if (count($targets) !== 1) {
                return false;
            }
        }
        if ($targets === []) {
            return false;
        }

        foreach ($targets as $i) {
            if ($action === '爽约') {
                $cards[$i]['noShow'] = true;
            } else {
                $cards[$i]['attended'] = true;
                $cards[$i]['noShow'] = false;
            }
        }
        $lead->update(['trial_cards' => $cards]);

        return true;
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
                    /*
                     * 随心瑜体验预约：没有直接的留资外键，只能按手机号反查。
                     *
                     * ══ 为什么现场大面积「点了没反应」（t3 排查结论，改之前先读）═══════
                     * ① **两套独立事实**：KY 的体验预约与「留资」是两条链路，前台在随心瑜
                     *    排体验课时**不会**自动生成留资记录 —— 没有 lead 就无从联动，
                     *    这不是缺陷，是数据缺失（处置见下方 flowNote 与文档）。
                     * ② **号码形态不一致（本次修掉的主因）**：`normalizePhone()` 只去分隔符，
                     *    不处理国家码/长途前缀。KY 真实存在 `+8613800000002`（归一后 13 位
                     *    `8613800000002`），留资里同一个人是 11 位 `13800000002`；
                     *    旧实现拿归一值做**等值**比较，位数不同就永远等不上。
                     *    现在统一走 `self::phoneForms()` 的等价形态集合。
                     * ③ **终态排除（设计如此）**：留资已成交/已流失时**故意**不回退状态 ——
                     *    留资状态机是唯一事实源，把成交客户改回「已体验」是数据事故。
                     * ④ **多命中不联动（设计如此）**：同号多条进行中留资属于歧义，
                     *    宁可让员工手动去留资管理改，也不把状态写到可能不是本人的那条上。
                     * ⑤ **权限**：唯一命中但当前账号无权处理该留资时不写状态（见下方 canHandle）。
                     *
                     * ③④⑤ 不是 bug。所以每一个「不联动」都往 flowNote 里写清**具体原因**，
                     * 随审计详情落库 —— 否则「点了没反应」永远只能靠猜。
                     */
                    $booking = KyBooking::find((int) $m[1]);
                    $forms = self::phoneForms($booking->phone ?? null);
                    if ($forms === []) {
                        $flowNote = '；预约行没有手机号，无法反查留资';
                    } else {
                        // 一次取全（含终态）后在内存里分流：只有这样才能分别说清
                        // 「该号码根本没有留资」与「留资已终态」—— 两种情况的处置完全不同。
                        $candidates = Lead::whereIn('phone', $forms)->orderByDesc('id')->get();
                        $active = $candidates
                            ->reject(fn ($l) => in_array((string) $l->status, ['已成交', '已流失'], true))
                            ->values();
                        if ($active->count() === 1) {
                            $lead = $active->first();
                            // KY 侧没有留资权限关系（预约行的归属不是留资归属），
                            // 唯一命中时按「当日标记」放行，但仍要求 canHandle 才写状态（见下）
                            $enforceLeadPermission = false;
                        } elseif ($active->count() > 1) {
                            $flowNote = '；同手机号命中 '.$active->count().' 条进行中留资，未联动状态';
                        } elseif ($candidates->isNotEmpty()) {
                            $flowNote = '；该留资已是终态（已成交/已流失），状态不回退';
                        } else {
                            $flowNote = '；该手机号没有留资记录（随心瑜体验预约与留资是两套事实）';
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
                    if (! $canHandle) {
                        // KY 分支不 abort（当日待办标记本身要落库），但状态确实没写 ——
                        // 必须留痕，否则前端看到「已勾选」会以为留资也同步了。
                        $flowNote .= '；当前账号无权处理该留资，状态未联动';
                    } else {
                        $next = match ($d['action']) {
                            '已接待' => '已体验',
                            '爽约' => '爽约',
                            default => null,
                        };
                        if ($next !== null && ! in_array((string) $lead->status, ['已成交', '已流失'], true) && (string) $lead->status !== $next) {
                            $lead->update(['status' => $next]);
                            audit($r, '标记处理', '前端客资', $lead->id, "{$lead->name}（{$lead->source}）", $lead->venue, "今日待办体验课标记 {$d['action']}：状态变更为 {$next}");
                        }
                        /*
                         * 卡片结果标记（attended / noShow）：唯一口径在 markTrialCardResult()。
                         *  - 留资体验课（trial:lead-X-S）：按节次精确回写；
                         *  - 随心瑜体验预约（trial:ky-Y，无节次）：只有留资侧**恰好一张今天的
                         *    未取消卡**时才回写，多张就返回 false 并把原因写进 flowNote。
                         * 原先 `$session > 0` 的守卫意味着 KY 分支**永远不碰 trial_cards**，
                         * 于是「留资管理里那张体验卡一直显示未上课」—— 本次补上。
                         */
                        if (in_array($d['action'], ['已接待', '爽约'], true)
                            && $this->markTrialCardResult($lead, $session, (string) $d['action'], $today)) {
                            if ($session <= 0) {
                                $flowNote .= '；已同步留资体验卡结果';
                            }
                        } elseif ($session <= 0 && in_array($d['action'], ['已接待', '爽约'], true)) {
                            $flowNote .= '；留资侧没有（或不止一张）今天的体验卡，未回写卡片结果';
                        }
                    }
                }
            }

            // 业务流转 2：续费/流失沟通 → 更新会员最近触达，保证 2 周触达口径不断档
            //
            // 同时把**沟通内容落到会员档案的留痕**（`module = 会员管理`，`target_id = 会员 id`）。
            // 为什么必须落在这里：小程序员工真机反馈「点个已沟通太形式化，换人接手时对这个
            // 客户还是不清楚」—— 因为 `TodoAction.remark` 按 `(todo_key, action_date)` 覆盖，
            // 只保留当天最后一条，而且会员 360 读的是 `AuditLog` 里 `module = 会员管理` 的记录
            // （`CustomerController::show()`），两边不通。沟通内容是**交接事实**，必须进流水：
            // 一条沟通一条日志，谁在什么时候说了什么，下一个服务老师点开会员就看得到。
            //
            // 只在 `remark` 非空时写：空备注写一条「已沟通：」是噪音，历史留痕会被灌满。
            if (! empty($d['customerId'])) {
                $c = Customer::find($d['customerId']);
                if ($c) {
                    abort_unless(canAccessCustomer($u, $c), 403, '无权处理该会员');
                    if (! empty($d['touch'])) {
                        $c->update(['last_touch' => $today]);
                    }
                    $remarkText = (string) ($d['remark'] ?? '');
                    if ($remarkText !== '') {
                        audit(
                            $r,
                            '沟通记录',
                            '会员管理',
                            $c->id,
                            "{$c->name}（{$c->venue}）",
                            $c->venue,
                            "{$d['action']}：{$remarkText}"
                        );
                    }
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
