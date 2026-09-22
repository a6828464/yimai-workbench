<?php

namespace App\Http\Controllers;

use App\Models\PayrollMonthlyInput;
use App\Models\PayrollProfile;
use App\Support\PayrollMoney;
use App\Support\PayrollRoles;
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
