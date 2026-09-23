<?php

namespace App\Http\Controllers;

use App\Models\KyBooking;
use App\Models\Lead;
use App\Models\PayrollMonthlyInput;
use App\Models\PayrollProfile;
use App\Models\User;
use App\Support\PayrollMoney;
use App\Support\PayrollRoles;
use App\Services\PayrollNameResolver;
use App\Services\PayrollPerformanceImportService;
use App\Services\PayrollService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 薪酬计算（**仅超管**）。
 *
 * 口径与 API 契约见 `docs/薪酬/薪酬计算栏目规格.md`；计算实现全部在
 * `App\Services\PayrollService` / `PayrollPerformanceImportService`，本控制器只做
 * 「鉴权 → 校验 → 调服务 → 包响应」，不在这里写任何计算规则
 * （否则就会出现第二套口径）。
 *
 * ## 权限
 *
 * **每个端点首行 `requireSuper($r)`**，不依赖路由 middleware —— 与 `AccountController`
 * / `BackupController` 的既有写法一致。前端隐藏菜单不算权限控制，后端 403 才是硬闸门。
 */
class PayrollController extends Controller
{
    public function __construct(
        private PayrollService $payroll,
        private PayrollPerformanceImportService $importer,
    ) {}

    // ------------------------------------------------------------------
    // 身份标签枚举（唯一下发点）
    // ------------------------------------------------------------------

    /**
     * GET /api/payroll/roles
     *
     * 前端**必须**从这里取枚举与规则说明，禁止在 `payroll/` 下再写一份硬编码列表。
     */
    public function roles(Request $r)
    {
        requireSuper($r);

        return ok([
            'roles' => PayrollRoles::catalog(),
            'venues' => PayrollRoles::VENUES,
            'statuses' => PayrollRoles::STATUSES,
            'dualBaseSalaryWhitelist' => PayrollRoles::DUAL_BASE_SALARY_WHITELIST,
            'activityTypes' => PayrollRoles::activityTypes(),
            'socialSecurityModes' => [
                ['value' => 'set', 'label' => '本月已设（含显式 0）'],
                ['value' => 'inherit', 'label' => '本月未操作（沿用最近一次有效设置）'],
                ['value' => 'off', 'label' => '本月不缴（打断继承链）'],
            ],
            'commissionTiers' => PayrollRoles::COMMISSION_TIERS,
            'baseRewardTiers' => PayrollRoles::BASE_REWARD_TIERS,
            'hourlyIncentiveTiers' => PayrollRoles::HOURLY_INCENTIVE_TIERS,
            // 两个 0.75 分别下发，避免前端/后续维护者误以为是同一个系数
            'fee45FallbackFactor' => (float) PayrollRoles::FEE_45_FALLBACK_FACTOR,
            'incentive45Factor' => (float) PayrollRoles::INCENTIVE_45_FACTOR,
        ]);
    }

    // ------------------------------------------------------------------
    // 课时统计
    // ------------------------------------------------------------------

    /** GET /api/payroll/hours?month=&venue= */
    public function hours(Request $r)
    {
        requireSuper($r);
        $month = $this->validMonth($r);
        $venue = $this->validVenue($r);

        return ok($this->payroll->hours($month, $venue));
    }

    // ------------------------------------------------------------------
    // 薪酬档案
    // ------------------------------------------------------------------

    /** GET /api/payroll/profiles?venue=&status=&role= */
    public function profiles(Request $r)
    {
        requireSuper($r);
        $venue = $this->validVenue($r);

        $q = PayrollProfile::query()->orderBy('venue')->orderBy('id');
        if ($venue !== null) {
            $q->where('venue', $venue);
        }
        if ($r->query('status') !== null && $r->query('status') !== '') {
            $q->where('status', (string) $r->query('status'));
        }
        if ($r->query('role') !== null && $r->query('role') !== '') {
            $q->where('role', PayrollRoles::normalizeRole((string) $r->query('role')));
        }
        $rows = $q->get()->map(fn (PayrollProfile $p) => $p->toApiArray())->all();

        return ok([
            'rows' => $rows,
            'roles' => PayrollRoles::catalog(),
            'venues' => PayrollRoles::VENUES,
        ]);
    }

    /**
     * PUT /api/payroll/profiles/{userId}
     *
     * `{userId}` 走的是 `users.id`（与规格 §7.4 一致）。**先按 user_id 找，找不到再按
     * profile id 找** —— 系统里大量薪酬人员没有登录账号，只按 user_id 找会无法维护他们。
     */
    public function updateProfile(Request $r, string $userId)
    {
        requireSuper($r);

        $profile = PayrollProfile::where('user_id', (int) $userId)->first()
            ?? PayrollProfile::find((int) $userId);
        abort_if($profile === null, 404, '找不到该薪酬档案');

        $data = $r->validate([
            'venue' => 'nullable|string|max:16',
            'role' => 'nullable|string|max:32',
            'baseSalary' => 'nullable|numeric|min:0|max:999999',
            'performance' => 'nullable|numeric|min:0|max:999999',
            'feePrivate60' => 'nullable|numeric|min:0|max:999999',
            'feePrivate45' => 'nullable|numeric|min:0|max:999999',
            'feeSmall' => 'nullable|numeric|min:0|max:999999',
            'feeGroup' => 'nullable|numeric|min:0|max:999999',
            'feeEnterprise' => 'nullable|numeric|min:0|max:999999',
            'dualBaseSalary' => 'nullable|boolean',
            'storeCommissionRate' => 'nullable|numeric|min:0|max:1',
            'commissionFixedRate' => 'nullable|numeric|min:0|max:1',
            'status' => 'nullable|string|max:16',
            'alert' => 'nullable|string|max:200',
            'accountStatus' => 'nullable|string|max:60',
            'note' => 'nullable|string|max:200',
            // 待完善：true=不参与计算；false/不传且身份标签合法时自动解除
            'pendingReview' => 'nullable|boolean',
            'aliases' => 'nullable|array|max:60',
            'aliases.*' => 'string|max:60',
        ]);

        // 身份标签必须是存储层枚举（`{门店}:顾问` 写法先归一化）
        $role = PayrollRoles::normalizeRole($data['role'] ?? $profile->role);
        if (! PayrollRoles::isValidRole($role)) {
            $this->fail(422, 'ROLE_NOT_ALLOWED', "身份标签「{$role}」不在枚举内");
        }
        $venue = $data['venue'] ?? $profile->venue;
        if ($venue !== '' && ! PayrollRoles::isValidVenue($venue)) {
            $this->fail(422, 'INVALID_VENUE', "门店「{$venue}」不是 东部店/绿地店");
        }

        $base = $data['baseSalary'] ?? (float) $profile->base_salary;
        $perf = $data['performance'] ?? (float) $profile->performance;

        // 兼职老师底薪/绩效强制 0（S1:170；S7 PARTTIME_FIXED 硬闸门）
        if ($role === '兼职老师' && ((float) $base !== 0.0 || (float) $perf !== 0.0)) {
            $this->fail(422, 'PARTTIME_FIXED_SALARY', '兼职老师的底薪与绩效必须为 0（兼职只按课时计费）');
        }

        $dual = array_key_exists('dualBaseSalary', $data)
            ? (bool) $data['dualBaseSalary']
            : (bool) $profile->dual_base_salary;
        if ($dual && ! in_array($profile->name, PayrollRoles::DUAL_BASE_SALARY_WHITELIST, true)) {
            $this->fail(
                422,
                'DUAL_BASE_NOT_ALLOWED',
                "「{$profile->name}」不在双底薪例外名单内（仅 ".implode('、', PayrollRoles::DUAL_BASE_SALARY_WHITELIST).'）'
            );
        }

        $before = $profile->toApiArray();

        DB::transaction(function () use ($profile, $data, $role, $venue, $base, $perf, $dual, $r) {
            $profile->fill(array_filter([
                'venue' => $venue,
                'role' => $role,
                'base_salary' => $base,
                'performance' => $perf,
                'fee_private60' => $data['feePrivate60'] ?? null,
                'fee_private45' => $data['feePrivate45'] ?? null,
                'fee_small' => $data['feeSmall'] ?? null,
                'fee_group' => $data['feeGroup'] ?? null,
                'fee_enterprise' => $data['feeEnterprise'] ?? null,
                'store_commission_rate' => array_key_exists('storeCommissionRate', $data) ? $data['storeCommissionRate'] : null,
                'commission_fixed_rate' => array_key_exists('commissionFixedRate', $data) ? $data['commissionFixedRate'] : null,
                'status' => $data['status'] ?? null,
                'alert' => $data['alert'] ?? null,
                'account_status' => $data['accountStatus'] ?? null,
                'note' => $data['note'] ?? null,
            ], fn ($v) => $v !== null));
            $profile->dual_base_salary = $dual;
            // 「保存」即「已确认」：用户在界面上看过并补齐了身份标签与单价，
            // 这次保存就是他确认的动作。显式传 null 时保持原值（避免老前端
            // 不带该字段的请求把已确认的档案又打回待完善）。
            if (array_key_exists('pendingReview', $data)) {
                $profile->pending_review = (bool) $data['pendingReview'];
            } elseif ((bool) $profile->pending_review) {
                // 身份标签已确认（枚举合法）才允许自动解除待完善；
                // 留空保存不放行 —— 那等于没有确认过算法分叉点
                if ($role !== '' && PayrollRoles::isValidRole($role)) {
                    $profile->pending_review = false;
                }
            }
            $profile->save();

            if (array_key_exists('aliases', $data)) {
                $this->syncAliases($profile, (array) $data['aliases']);
            }
        });

        $profile->refresh();
        $after = $profile->toApiArray();

        // 审计留痕（含改动前后，便于追溯「谁把谁的课时费改了」）
        audit(
            $r,
            '更新薪酬档案',
            '薪酬计算',
            $profile->id,
            $profile->name,
            (string) $profile->venue,
            json_encode(['before' => $before, 'after' => $after], JSON_UNESCAPED_UNICODE) ?: ''
        );

        return ok(['profile' => $after, 'changed' => $before !== $after]);
    }

    /**
     * POST /api/payroll/profiles
     *
     * 新增建档。此前**只有 PUT 没有 POST** —— 档案全部由人员主档 seeder 导入，
     * 界面既没有「新增」按钮也没有对应端点，于是主档之外的人（新入职、兼职）
     * 根本无从建档。用户反馈「我没有找到新增建档的选项」即此。
     *
     * 新建的档案一律 `pending_review = true`：此时身份标签与单价都还没确认，
     * 而「字段留空」在计算侧不等于「不参与计算」（见 `PayrollService::calculate()`
     * 的待完善闸门），所以必须显式标成待完善，否则会被按默认身份标签算出一笔工资。
     */
    public function storeProfile(Request $r)
    {
        requireSuper($r);

        $data = $r->validate([
            'name' => 'required|string|max:60',
            'venue' => 'required|string|max:16',
            'role' => 'nullable|string|max:32',
            // 展示层岗位（如「{门店}全职老师」）不得回写存储层，这里只收存储层枚举
            'status' => 'nullable|string|max:16',
            'note' => 'nullable|string|max:200',
            'aliases' => 'nullable|array|max:60',
            'aliases.*' => 'string|max:60',
        ]);

        $name = trim($data['name']);
        $venue = trim($data['venue']);
        if (! PayrollRoles::isValidVenue($venue)) {
            $this->fail(422, 'INVALID_VENUE', "门店「{$venue}」不是 东部店/绿地店");
        }

        // 同名同店已存在 ⇒ 拒绝，避免同一人两条档案各算一份工资
        $exists = PayrollProfile::where('name', $name)->where('venue', $venue)->exists();
        if ($exists) {
            $this->fail(422, 'PROFILE_EXISTS', "「{$name}」在{$venue}已有薪酬档案，请直接编辑该档案");
        }

        $role = PayrollRoles::normalizeRole($data['role'] ?? '');
        if ($role !== '' && ! PayrollRoles::isValidRole($role)) {
            $this->fail(422, 'ROLE_NOT_ALLOWED', "身份标签「{$role}」不在枚举内");
        }

        $profile = DB::transaction(function () use ($name, $venue, $role, $data) {
            $p = new PayrollProfile;
            $p->name = $name;
            $p->venue = $venue;
            // 身份标签未选时不写默认值，留空串；空串在计算侧由 pending_review 拦下
            $p->role = $role;
            $p->status = $data['status'] ?? '有效';
            $p->note = $data['note'] ?? '';
            $p->pending_review = true;
            // 账号：只做「同名唯一」自动绑定，对不上留空（不猜）
            $hits = User::where('name', $name)->limit(2)->pluck('id');
            if ($hits->count() === 1) {
                $p->user_id = (int) $hits->first();
            }
            $p->save();

            if (! empty($data['aliases'])) {
                $this->syncAliases($p, (array) $data['aliases']);
            }

            return $p;
        });

        $after = $profile->fresh()->toApiArray();
        audit($r, '新增薪酬档案', '薪酬计算', $profile->id, $profile->name, $profile->venue, '新增建档（待完善）');

        return ok(['profile' => $after]);
    }

    /**
     * POST /api/payroll/profiles/prefill
     *
     * 「系统已知信息自动预填」：把系统里**确实存在的人**扫出来建档，已知的字段填好，
     * 未知的（身份标签、底薪、课时费）**留空并标待完善**，由用户在界面上补齐。
     *
     * ## 为什么需要它
     *
     * 薪酬档案此前只能由人员主档 xlsx 导入，而那是**仓库外的高敏文件**
     * （`PAYROLL_MASTER_XLSX`）。没配这个文件的部署（含测试服务器）档案表是空的，
     * 手工一条条建 50+ 人不现实，于是「课时费与身份标签」页永远空着。
     *
     * ## 数据来源：只取系统里真实出现过的老师
     *
     * 三个来源并集，都是**系统自己的事实**，不是猜的：
     *
     * | 来源 | 字段 | 提供了什么 |
     * |---|---|---|
     * | `ky_bookings` | `teacher_name` + `venue` | 真正上过课的人及其门店（最可靠） |
     * | `users` | `name` + `venue` | 有登录账号的员工 |
     * | `leads` | `trial_teacher` + `venue` | 留资里登记的上课老师 |
     *
     * **不编造任何金额**：底薪/绩效/各课型单价一律留 0 且标待完善，
     * 身份标签留空 —— 这三项都必须由用户确认，因为它们是算钱的输入。
     *
     * ## 为什么别名不自动填
     *
     * 别名（苏米→罗柳柳）只有人员主档里才有权威对应关系。系统里的
     * `teacher_name` 本身就是别名（如「苏米」），把它当**本名**建档会让
     * 「苏米」既做本名又做别名，反而污染解析（`PayrollNameResolver` 一个名字
     * 只能对一个人）。所以这里把**看起来是别名/无法确认的姓名原样作为档案名**
     * 建出来、并在备注里标注来源，由用户改成真名后自行登记别名。
     *
     * ## 🔴 为什么必须先用 PayrollNameResolver 判重（否则会砸掉现有的姓名解析）
     *
     * 系统里出现的 `teacher_name` **多半是别名而不是本名**：实测
     * 冰璐→钱冰璐、芷晴/张芷晴→张情、苏米→罗柳柳、娟子→徐秀娟、婷婷→谭婷婷
     * 全都已经能解析到既有档案。若按「姓名+门店」判重就建新档，
     * 一个名字会对应**两个**档案，`PayrollNameResolver` 立刻判定为歧义 →
     * `resolve()` 返回 null → **这个人从课时统计里整个消失**（原来明明是好的）。
     *
     * 所以判重必须问解析器「这个名字现在解析得到谁」，而不是只比字面。
     * 命中既有档案的一律跳过（并在 `skipped` 里说明命中了谁），
     * 用户若要给某人加别名，去编辑那条既有档案即可。
     *
     * `dryRun` 只返回将要建档的清单，不落库（界面先给用户看一眼再确认）。
     */
    public function prefillProfiles(Request $r)
    {
        requireSuper($r);
        $dryRun = (bool) $r->input('dryRun', false);

        // ---- 1. 真正上过课的人：teacher_name + venue（最可靠的一手事实） ----
        $fromBookings = KyBooking::query()
            ->whereNotNull('teacher_name')
            ->where('teacher_name', '!=', '')
            ->where('venue', '!=', '')
            ->select('teacher_name', 'venue', DB::raw('count(*) as classes'))
            ->groupBy('teacher_name', 'venue')
            ->get();

        // ---- 2. 有登录账号的员工 ----
        $fromUsers = User::query()
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->get(['name', 'venue']);

        // ---- 3. 留资里登记的上课老师（含尚未产生课次的新老师） ----
        $fromLeads = Lead::query()
            ->whereNotNull('trial_teacher')
            ->where('trial_teacher', '!=', '')
            ->where('venue', '!=', '')
            ->select('trial_teacher', 'venue', DB::raw('count(*) as leads'))
            ->groupBy('trial_teacher', 'venue')
            ->get();

        // 汇总：姓名+门店 为键（同一人在两店都上过课 ⇒ 建两行，与档案自带
        // venue 的语义一致：venue 是「工资所属门店」，跨店授课是两店各算）
        $candidates = [];
        $note = function (array &$c, string $source, int $n): void {
            $c['sources'][$source] = ($c['sources'][$source] ?? 0) + $n;
        };
        foreach ($fromBookings as $b) {
            $key = trim((string) $b->teacher_name).'|'.trim((string) $b->venue);
            $candidates[$key] ??= ['name' => trim((string) $b->teacher_name), 'venue' => trim((string) $b->venue), 'sources' => []];
            $note($candidates[$key], 'bookings', (int) $b->classes);
        }
        foreach ($fromUsers as $u) {
            $name = trim((string) $u->name);
            $venue = trim((string) $u->venue);
            if ($name === '' || $venue === '') {
                continue;
            }
            $key = $name.'|'.$venue;
            $candidates[$key] ??= ['name' => $name, 'venue' => $venue, 'sources' => []];
            $note($candidates[$key], 'users', 1);
        }
        foreach ($fromLeads as $l) {
            $name = trim((string) $l->trial_teacher);
            $venue = trim((string) $l->venue);
            $key = $name.'|'.$venue;
            $candidates[$key] ??= ['name' => $name, 'venue' => $venue, 'sources' => []];
            $note($candidates[$key], 'leads', (int) $l->leads);
        }

        // 去掉门店非法的（'东部店'/'绿地店' 之外的一律不建，避免造出无法计算的行）
        $candidates = array_filter($candidates, fn ($c) => PayrollRoles::isValidVenue($c['venue']));

        // 去掉已建档的。**用解析器判重而不是比字面**（见方法注释：按字面判重会
        // 给别名再建一条档案，使该名字变成歧义 → 本人从课时统计里整个消失）
        $resolver = app(PayrollNameResolver::class);

        $toCreate = [];
        $skipped = [];
        foreach ($candidates as $key => $c) {
            $hit = $resolver->resolve($c['name']);
            if ($hit !== null) {
                $skipped[] = [
                    'name' => $c['name'],
                    'venue' => $c['venue'],
                    'reason' => $c['name'] === $hit->name
                        ? "已建档（#{$hit->id} {$hit->name}）"
                        : "「{$c['name']}」是别名，已指向既有档案 #{$hit->id} {$hit->name}",
                ];
                continue;
            }
            $toCreate[] = [
                'name' => $c['name'],
                'venue' => $c['venue'],
                'sources' => array_keys($c['sources']),
                'counts' => $c['sources'],
            ];
        }

        usort($toCreate, fn ($a, $b) => [$a['venue'], $a['name']] <=> [$b['venue'], $b['name']]);

        if ($dryRun) {
            return ok([
                'dryRun' => true,
                'willCreate' => $toCreate,
                'skipped' => $skipped,
                'created' => 0,
            ]);
        }

        $created = 0;
        DB::transaction(function () use ($toCreate, &$created) {
            foreach ($toCreate as $c) {
                $p = new PayrollProfile;
                $p->name = $c['name'];
                $p->venue = $c['venue'];
                // 身份标签留空串：**不猜**。由待完善闸门保证它不参与计算
                $p->role = '';
                $p->status = '有效';
                $p->pending_review = true;
                $p->note = '系统预填（来源：'.implode('/', $c['sources']).'）。'
                    .'身份标签与课时费待确认；若该姓名是别名（如「苏米」），请改成本名并登记别名';
                $hits = User::where('name', $c['name'])->limit(2)->pluck('id');
                if ($hits->count() === 1) {
                    $p->user_id = (int) $hits->first();
                }
                $p->save();
                $created++;
            }
        });

        audit($r, '批量预填薪酬档案', '薪酬计算', 0, '预填建档', '', "新增 {$created} 条待完善档案");

        return ok([
            'dryRun' => false,
            'created' => $created,
            'willCreate' => $toCreate,
            'skipped' => $skipped,
        ]);
    }

    // ------------------------------------------------------------------
    // 业绩导入
    // ------------------------------------------------------------------

    /** POST /api/payroll/performance/preview（上传 + 解析，**不落库**） */
    public function performancePreview(Request $r)
    {
        requireSuper($r);
        $r->validate([
            'file' => 'required|file|max:10240',
            'venue' => 'required|string',
            'month' => 'required|string',
        ]);
        $venue = $this->validVenueRequired($r);
        $month = $this->validMonth($r);

        $parsed = $this->parseUploaded($r, $venue, $month);
        $parsed['payload']['dryRun'] = true;
        $parsed['payload']['unchanged'] = false;
        $parsed['payload']['replaced'] = ['rows' => 0];

        return ok($parsed['payload']);
    }

    /** POST /api/payroll/performance/commit */
    public function performanceCommit(Request $r)
    {
        requireSuper($r);
        $r->validate([
            'file' => 'required|file|max:10240',
            'venue' => 'required|string',
            'month' => 'required|string',
            'previewSha256' => 'nullable|string|size:64',
            'allowExceptions' => 'nullable|boolean',
        ]);
        $venue = $this->validVenueRequired($r);
        $month = $this->validMonth($r);

        // 重新上传、重新解析（不复用临时文件，避免 TOCTOU）
        $parsed = $this->parseUploaded($r, $venue, $month);
        $sha = (string) $parsed['payload']['sourceSha256'];

        // preview 与 commit 之间文件被换掉 → 409，避免「预览看到的」和「写进去的」不是同一份
        $previewSha = (string) $r->input('previewSha256', '');
        if ($previewSha !== '' && $previewSha !== $sha) {
            return response()->json([
                'message' => '文件与预览时不一致（可能已被替换），请重新预览后再提交',
                'code' => 'PREVIEW_STALE',
            ], 409);
        }

        try {
            $payload = $this->importer->commit($parsed, $sha, (bool) $r->boolean('allowExceptions'));
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'COMMIT_HAS_EXCEPTIONS') {
                $this->fail(
                    422,
                    'COMMIT_HAS_EXCEPTIONS',
                    '本次解析存在异常行（分配不平 / 归属缺失 / 姓名对不上），默认拒绝写入；'
                    .'请先在预览里核对异常清单，或显式传 allowExceptions=true（异常行不入库）'
                );
            }
            throw $e;
        }

        audit(
            $r,
            '导入业绩表',
            '薪酬计算',
            $venue.'/'.$month,
            $venue.' '.$month,
            $venue,
            json_encode([
                'sourceFileName' => $payload['sourceFileName'] ?? '',
                'sourceSha256' => $sha,
                'counts' => $payload['counts'] ?? [],
                'totals' => $payload['totals'] ?? [],
                'unchanged' => $payload['unchanged'] ?? false,
                'replaced' => $payload['replaced'] ?? [],
            ], JSON_UNESCAPED_UNICODE) ?: ''
        );

        return ok($payload);
    }

    /** GET /api/payroll/performance?month=&venue= */
    public function performanceIndex(Request $r)
    {
        requireSuper($r);
        $month = $this->validMonth($r);
        $venue = $this->validVenue($r);

        return ok($this->importer->summary($month, $venue));
    }

    // ------------------------------------------------------------------
    // 月度输入（考勤 / 社保 / 个税）
    // ------------------------------------------------------------------

    /** GET /api/payroll/monthly-inputs?month=&venue= */
    public function monthlyInputsShow(Request $r)
    {
        requireSuper($r);
        $month = $this->validMonth($r);
        $venue = $this->validVenue($r);

        return ok($this->payroll->monthlyInputRows($month, $venue));
    }

    /** PUT /api/payroll/monthly-inputs（批量 upsert，响应回显最终生效值） */
    public function monthlyInputsUpdate(Request $r)
    {
        requireSuper($r);
        $r->validate([
            'month' => 'required|string',
            'venue' => 'nullable|string',
            'rows' => 'required|array|min:1',
            'rows.*.profileId' => 'required|integer',
            'rows.*.attendanceDays' => 'nullable|numeric|min:0|max:31',
            'rows.*.personalLeaveHours' => 'nullable|numeric|min:0|max:744',
            'rows.*.sickLeaveHours' => 'nullable|numeric|min:0|max:744',
            'rows.*.socialSecurity' => 'nullable|numeric|min:0|max:999999',
            'rows.*.socialSecurityMode' => 'nullable|string|in:inherit,set,off',
            'rows.*.tax' => 'nullable|numeric|min:0|max:999999',
            'rows.*.subsidy' => 'nullable|numeric|min:-999999|max:999999',
            'rows.*.previousAdjustment' => 'nullable|numeric|min:-999999|max:999999',
            'rows.*.otherDeduction' => 'nullable|numeric|min:0|max:999999',
            'rows.*.fixedSalaryOverride' => 'nullable|numeric|min:0|max:999999',
            'rows.*.baseSalaryZeroed' => 'nullable|boolean',
            'rows.*.storeCommissionAddon' => 'nullable|numeric|min:-999999|max:999999',
            'rows.*.note' => 'nullable|string|max:200',
        ]);
        $month = $this->validMonth($r);

        $changed = [];
        DB::transaction(function () use ($r, $month, &$changed) {
            foreach ((array) $r->input('rows', []) as $row) {
                $profile = PayrollProfile::find((int) $row['profileId']);
                if ($profile === null) {
                    $this->fail(422, 'PROFILE_NOT_FOUND', "薪酬档案 #{$row['profileId']} 不存在");
                }
                $mode = (string) ($row['socialSecurityMode'] ?? PayrollMonthlyInput::SOCIAL_INHERIT);
                if (! in_array($mode, PayrollMonthlyInput::SOCIAL_MODES, true)) {
                    $this->fail(422, 'SOCIAL_SECURITY_MODE_INVALID', "社保模式「{$mode}」非法（应为 inherit/set/off）");
                }
                // `set` 必须带金额（可为 0，但必须显式给出）—— 否则无法与「未操作」区分
                if ($mode === PayrollMonthlyInput::SOCIAL_SET && ! array_key_exists('socialSecurity', $row)) {
                    $this->fail(422, 'SOCIAL_SECURITY_MODE_INVALID', '社保模式为 set 时必须提供 socialSecurity 金额（显式 0 也要传）');
                }

                $input = PayrollMonthlyInput::firstOrNew([
                    'venue' => $profile->venue,
                    'payroll_profile_id' => $profile->id,
                    'month' => $month,
                ]);
                $input->fill([
                    'user_id' => $profile->user_id,
                    'attendance_days' => $row['attendanceDays'] ?? null,
                    'personal_leave_hours' => $row['personalLeaveHours'] ?? null,
                    'sick_leave_hours' => $row['sickLeaveHours'] ?? null,
                    // set 取传入值（含显式 0）；inherit/off 忽略传入值并清空金额，
                    // 避免「上次设过 500、这次改成 inherit」时残留一个会被误读的 500
                    'social_security' => $mode === PayrollMonthlyInput::SOCIAL_SET
                        ? ($row['socialSecurity'] ?? 0)
                        : null,
                    'social_security_mode' => $mode,
                    'tax' => $row['tax'] ?? null,
                    'subsidy' => $row['subsidy'] ?? null,
                    'previous_adjustment' => $row['previousAdjustment'] ?? null,
                    'other_deduction' => $row['otherDeduction'] ?? null,
                    'fixed_salary_override' => $row['fixedSalaryOverride'] ?? null,
                    'base_salary_zeroed' => (bool) ($row['baseSalaryZeroed'] ?? false),
                    'store_commission_addon' => $row['storeCommissionAddon'] ?? null,
                    'note' => $row['note'] ?? '',
                    'updated_by' => $r->user()->id,
                ]);
                $input->save();
                $changed[] = $profile->name;
            }
        });

        audit(
            $r,
            '更新薪酬月度输入',
            '薪酬计算',
            $month,
            $month,
            (string) ($r->input('venue') ?? ''),
            json_encode(['month' => $month, 'people' => $changed, 'rows' => $r->input('rows', [])], JSON_UNESCAPED_UNICODE) ?: ''
        );

        // 响应回显**最终生效值**（含 inherit 解析结果），不能只回显请求体
        return ok($this->payroll->monthlyInputRows($month, $this->validVenue($r)) + ['updated' => $changed]);
    }

    /**
     * POST /api/payroll/monthly-inputs/copy-from-previous
     *
     * 把上月**显式设置**的值复制为本月 `mode='set'` 记录。用于「这个月跟上月一样」的一键操作。
     */
    public function monthlyInputsCopyFromPrevious(Request $r)
    {
        requireSuper($r);
        $r->validate([
            'month' => 'required|string',
            'venue' => 'nullable|string',
            'fields' => 'nullable|array',
            'fields.*' => 'string|in:socialSecurity,attendanceDays,personalLeaveHours,sickLeaveHours,tax,subsidy',
        ]);
        $month = $this->validMonth($r);
        $venue = $this->validVenue($r);
        $fields = (array) $r->input('fields', ['socialSecurity', 'attendanceDays']);

        $prevMonth = date('Y-m', strtotime($month.'-01 -1 month'));
        $prev = PayrollMonthlyInput::query()
            ->where('month', $prevMonth)
            ->when($venue !== null, fn ($q) => $q->where('venue', $venue))
            ->get();

        $copied = 0;
        DB::transaction(function () use ($prev, $month, $fields, $r, &$copied) {
            foreach ($prev as $p) {
                $input = PayrollMonthlyInput::firstOrNew([
                    'venue' => $p->venue,
                    'payroll_profile_id' => $p->payroll_profile_id,
                    'month' => $month,
                ]);
                $input->user_id = $p->user_id;
                foreach ($fields as $f) {
                    $column = match ($f) {
                        'socialSecurity' => 'social_security',
                        'attendanceDays' => 'attendance_days',
                        'personalLeaveHours' => 'personal_leave_hours',
                        'sickLeaveHours' => 'sick_leave_hours',
                        'tax' => 'tax',
                        'subsidy' => 'subsidy',
                        default => null,
                    };
                    if ($column !== null) {
                        $input->{$column} = $p->{$column};
                    }
                }
                // 复制社保时写 `set`（本月**显式**设成与上月相同的值），
                // 而不是留 `inherit` —— 用户点的是「跟上月一样」，这是本月的一次明确操作
                if (in_array('socialSecurity', $fields, true) && $p->socialMode() !== PayrollMonthlyInput::SOCIAL_INHERIT) {
                    $input->social_security_mode = $p->socialMode();
                    $input->social_security = $p->social_security;
                }
                $input->updated_by = $r->user()->id;
                $input->save();
                $copied++;
            }
        });

        audit($r, '复制上月薪酬输入', '薪酬计算', $month, $month, (string) ($venue ?? ''), json_encode([
            'from' => $prevMonth, 'fields' => $fields, 'rows' => $copied,
        ], JSON_UNESCAPED_UNICODE) ?: '');

        return ok($this->payroll->monthlyInputRows($month, $venue) + ['copiedFrom' => $prevMonth, 'copied' => $copied]);
    }

    // ------------------------------------------------------------------
    // 薪酬计算
    // ------------------------------------------------------------------

    /** GET /api/payroll/calculate?month=&venue= */
    public function calculate(Request $r)
    {
        requireSuper($r);
        $month = $this->validMonth($r);
        $venue = $this->validVenue($r);

        return ok($this->payroll->calculate($month, $venue));
    }

    // ------------------------------------------------------------------
    // 内部
    // ------------------------------------------------------------------

    private function parseUploaded(Request $r, string $venue, string $month): array
    {
        $file = $r->file('file');
        $name = (string) $file->getClientOriginalName();
        if (! preg_match('/\.xlsx$/i', $name)) {
            $this->fail(422, 'INVALID_FILE', '只支持 .xlsx 文件（.xls 请先另存为 .xlsx）');
        }
        $path = $file->getRealPath();
        if ($path === false) {
            $this->fail(422, 'INVALID_FILE', '上传文件读取失败');
        }
        try {
            $parsed = $this->importer->parse($path, $venue, $month, $name);
        } catch (RuntimeException $e) {
            // 缺 zip 扩展 / 不是有效 xlsx / 找不到 sheet —— 都属文件或环境问题，
            // 回 422 带明确中文原因，而不是 500
            $this->fail(422, 'INVALID_FILE', $e->getMessage());
        }
        if (($parsed['fatal'] ?? null) !== null) {
            $msg = (string) ($parsed['exceptions'][0]['message'] ?? '文件无法解析');
            $this->fail(422, 'INVALID_FILE', $msg);
        }

        return $parsed;
    }

    private function validMonth(Request $r): string
    {
        $month = (string) ($r->input('month') ?? $r->query('month') ?? '');
        if (! PayrollService::isValidMonth($month)) {
            $this->fail(422, 'INVALID_MONTH', "月份「{$month}」格式非法，应为 YYYY-MM");
        }

        return $month;
    }

    private function validVenue(Request $r): ?string
    {
        $venue = $r->input('venue') ?? $r->query('venue');
        if ($venue === null || $venue === '') {
            return null;
        }
        if (! PayrollRoles::isValidVenue((string) $venue)) {
            $this->fail(422, 'INVALID_VENUE', "门店「{$venue}」不是 东部店/绿地店");
        }

        return (string) $venue;
    }

    private function validVenueRequired(Request $r): string
    {
        $venue = $this->validVenue($r);
        if ($venue === null) {
            $this->fail(422, 'INVALID_VENUE', '必须指定门店（东部店 / 绿地店），一个文件只对应一家店');
        }

        return $venue;
    }

    /** 统一错误形态：指定状态码 + 明确 code（规格 §7.8） */
    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(
            response()->json(['message' => $message, 'code' => $code], $status)
        );
    }

    /**
     * 同步别名（全局唯一，歧义时抛 422 而不是静默覆盖）。
     *
     * 唯一性校验放在写入侧：一个名字只能对一个人，撞车时**明确报错**而不是覆盖 ——
     * 覆盖会让上一次的归属静默改人（与 `staff_aliases.alias` 的唯一约束同语义）。
     */
    private function syncAliases(PayrollProfile $profile, array $aliases): void
    {
        $aliases = array_values(array_unique(array_filter(array_map(
            fn ($a) => trim((string) $a),
            $aliases
        ), fn ($a) => $a !== '' && $a !== $profile->name)));

        foreach ($aliases as $alias) {
            $taken = PayrollProfile::where('id', '!=', $profile->id)
                ->whereJsonContains('aliases', $alias)
                ->exists();
            if ($taken) {
                $this->fail(422, 'AMBIGUOUS_NAME', "别名「{$alias}」已属于其他人员，一个名字只能对一个人");
            }
        }
        $profile->aliases = $aliases;
        $profile->save();
    }
}
