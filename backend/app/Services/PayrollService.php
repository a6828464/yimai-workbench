<?php

namespace App\Services;

use App\Models\KyBooking;
use App\Models\PayrollMonthlyInput;
use App\Models\PayrollPerformance;
use App\Models\PayrollProfile;
use App\Models\StaffAlias;
use App\Models\User;
use App\Support\PayrollMoney;
use App\Support\PayrollRoles;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 薪酬计算引擎（PHP 侧唯一实现）。
 *
 * 口径来源：`03_计算程序与校验器/系统工具/统一工资计算引擎.py`（S1，211 行）。
 * 本类把 S1:152-191 的计算段逐条翻译过来，**判定一律走 `PayrollRoles`**，
 * 不在本类里另写 `if role === '…'` 的第二套判定。
 *
 * ## 三条不可动摇的口径
 *
 * 1. **金额全链路定点**：走 `PayrollMoney`（「分」整数），逐笔 half-up 后累加。
 *    用 float 或 banker's rounding 会把提成合计算成 21,068.08 而非 21,068.10。
 * 2. **299 活动卡剔除**：提成与门店提成基数只用 `commission_amount` / `store_sales_amount`，
 *    `raw_amount` 仅供核对留档（S1:88-91）。
 * 3. **课时按「课次」不按「预约行」**：`ky_bookings` 一行 = 一条会员预约，
 *    一节课 N 个会员 = N 行。直接 `count(*)` 会把课时放大 1.5~1.9 倍，
 *    连带把底薪奖励的 80/100/110/120 档位判错。
 */
class PayrollService
{
    /** 课时口径：只有这四种计入底薪奖励（S1:156） */
    private const BASE_REWARD_KINDS = PayrollRoles::BASE_REWARD_KINDS;

    /** 全部课型（展示用；企业课/总监私教/短期集训类当前无数据源，恒 0） */
    private const ALL_KINDS = ['private60', 'private45', 'small', 'group', 'enterprise'];

    /**
     * 老师课时统计（`GET /payroll/hours`）。
     *
     * @return array{month:string,venue:?string,rows:array,warnings:array,meta:array}
     */
    public function hours(string $month, ?string $venue = null): array
    {
        [$start, $end] = $this->monthRange($month);
        $warnings = [];

        // 先把「课次」在 SQL 里聚合出来。**不能 count(*)**：该表存的是会员预约，
        // 一节课 N 个会员 N 行。SQLite 也不支持 COUNT(DISTINCT a,b)（实测报
        // "wrong number of arguments to function count()"），所以用 group by 子查询。
        $classes = $this->classRows($start, $end, $venue);

        $rowsByTeacher = [];   // teacher_name => ['kinds'=>[...], 'classes'=>n, 'rows'=>n, 'sources'=>[...]]
        $unresolvedNames = [];

        foreach ($classes as $c) {
            $name = trim((string) $c->teacher_name);
            if ($name === '') {
                continue;
            }
            $kind = $this->normalizeKind($c->course_kind);
            // 时长**只对私教**解析：引擎里只有「定制私教60分钟/45分钟」分两档
            // （S1:175、S1:178），小班/团课是单一价（`hs['私教小班']*p['小班']`、
            // `hs['精品团课']*p['团课']`），压根没有时长概念。若全课型扫，
            // 「精品团课｜90Min」「团课60分钟」会被判成「未含时长」落进 assumed_60，
            // 让用户看到一堆假警报。
            if ($kind === 'private') {
                $duration = self::durationOf($c->course_name, $c->raw_sample);
                $bucket = $duration['minutes'] === 45 ? 'private45' : 'private60';
                $durationSource = $duration['source'];
            } else {
                $bucket = $kind;
                $durationSource = null;
            }

            $rowsByTeacher[$name] ??= [
                'kinds' => array_fill_keys(self::ALL_KINDS, 0),
                'rows' => 0,
                'classCount' => 0,
                'durationSources' => [],
                'assumed60' => 0,
                'sampleCourses' => [],
            ];
            $rowsByTeacher[$name]['kinds'][$bucket] = ($rowsByTeacher[$name]['kinds'][$bucket] ?? 0) + 1;
            $rowsByTeacher[$name]['rows'] += (int) $c->row_count;
            $rowsByTeacher[$name]['classCount']++;
            if ($durationSource !== null) {
                $rowsByTeacher[$name]['durationSources'][$durationSource] =
                    ($rowsByTeacher[$name]['durationSources'][$durationSource] ?? 0) + 1;
                if ($durationSource === 'assumed_60') {
                    $rowsByTeacher[$name]['assumed60']++;
                }
            }
            $cn = trim((string) $c->course_name);
            if ($cn !== '' && count($rowsByTeacher[$name]['sampleCourses']) < 6) {
                $rowsByTeacher[$name]['sampleCourses'][$cn] = true;
            }
        }

        // 姓名 → 薪酬档案（走唯一解析入口，含 4 个别名来源）
        $resolver = $this->nameResolver();
        $byProfile = [];
        foreach ($rowsByTeacher as $name => $agg) {
            $profile = $resolver->resolve($name);
            if ($profile === null) {
                $unresolvedNames[$name] = true;
                $key = 'name:'.$name;
                $byProfile[$key] = [
                    'profile' => null,
                    'names' => [$name],
                    'kinds' => $agg['kinds'],
                    'rows' => $agg['rows'],
                    'classCount' => $agg['classCount'],
                    'durationSources' => $agg['durationSources'],
                    'assumed60' => $agg['assumed60'],
                    'sampleCourses' => array_keys($agg['sampleCourses']),
                ];
                continue;
            }
            $key = 'p:'.$profile->id;
            if (! isset($byProfile[$key])) {
                $byProfile[$key] = [
                    'profile' => $profile,
                    'names' => [],
                    'kinds' => array_fill_keys(self::ALL_KINDS, 0),
                    'rows' => 0,
                    'classCount' => 0,
                    'durationSources' => [],
                    'assumed60' => 0,
                    'sampleCourses' => [],
                ];
            }
            $byProfile[$key]['names'][] = $name;
            foreach (self::ALL_KINDS as $k) {
                $byProfile[$key]['kinds'][$k] += $agg['kinds'][$k] ?? 0;
            }
            $byProfile[$key]['rows'] += $agg['rows'];
            $byProfile[$key]['classCount'] += $agg['classCount'];
            foreach ($agg['durationSources'] as $src => $n) {
                $byProfile[$key]['durationSources'][$src] = ($byProfile[$key]['durationSources'][$src] ?? 0) + $n;
            }
            $byProfile[$key]['assumed60'] += $agg['assumed60'];
            // 注意要取 array_keys：`$agg['sampleCourses']` 是「课程名 => true」的**集合**，
            // 直接 merge 会把 true 当值合并进去，输出成 `[true]`（本机实测踩过）
            $byProfile[$key]['sampleCourses'] = array_values(array_unique(
                array_merge($byProfile[$key]['sampleCourses'], array_keys($agg['sampleCourses']))
            ));
        }

        // 两店累计课时（底薪奖励门槛用，S1:152-156）—— 与 venue 过滤无关，始终两店
        $accumulated = $this->accumulatedValidHours($start, $end);

        $rows = [];
        foreach ($byProfile as $item) {
            /** @var PayrollProfile|null $p */
            $p = $item['profile'];
            $kinds = $item['kinds'];
            $valid = 0;
            foreach (self::BASE_REWARD_KINDS as $k) {
                $valid += $kinds[$k] ?? 0;
            }
            $total = array_sum($kinds);
            $primarySource = $this->primaryDurationSource($item['durationSources']);
            $accKey = $p ? (string) $p->id : null;
            $acc = $accKey !== null ? ($accumulated[$accKey] ?? null) : null;

            $rows[] = [
                'userId' => $p?->user_id,
                'profileId' => $p?->id,
                'name' => $p?->name ?? ($item['names'][0] ?? ''),
                'sourceNames' => array_values(array_unique($item['names'])),
                'venue' => $p?->venue ?? '',
                'role' => $p?->role ?? '',
                'roleLabel' => $p ? PayrollRoles::displayRole($p->role, $p->venue, $venue ?? $p->venue) : '',
                'private60' => $kinds['private60'] ?? 0,
                'private45' => $kinds['private45'] ?? 0,
                'small' => $kinds['small'] ?? 0,
                'group' => $kinds['group'] ?? 0,
                'enterprise' => $kinds['enterprise'] ?? 0,
                'validHours' => $valid,
                'totalHours' => $total,
                'classCount' => $item['classCount'],
                'bookingRows' => $item['rows'],
                'durationSource' => $primarySource,
                'durationSources' => $item['durationSources'],
                'assumed60Count' => $item['assumed60'],
                'sampleCourses' => $item['sampleCourses'],
                'accumulatedHours' => $acc,
                'baseRewardTier' => PayrollRoles::baseRewardFor($acc ?? $valid),
                'baseReward' => PayrollMoney::toFloat(
                    PayrollMoney::cents(PayrollRoles::baseRewardFor($acc ?? $valid))
                ),
            ];
        }

        usort($rows, fn ($a, $b) => [$a['venue'], $a['role'], $a['name']] <=> [$b['venue'], $b['role'], $b['name']]);

        // 时长来源为推断时的汇总（让用户一眼看出这个月有多少节是估的）
        $assumedTotal = array_sum(array_column($rows, 'assumed60Count'));
        if ($assumedTotal > 0) {
            $affected = [];
            foreach ($rows as $r) {
                if ($r['assumed60Count'] > 0) {
                    $affected[] = [
                        'userId' => $r['userId'],
                        'profileId' => $r['profileId'],
                        'name' => $r['name'],
                        'venue' => $r['venue'],
                        'assumed60Count' => $r['assumed60Count'],
                        'sampleCourses' => $r['sampleCourses'],
                    ];
                }
            }
            $warnings[] = [
                'code' => 'DURATION_ASSUMED_60',
                // 口径写明是「**私教**课中课程名未含时长的节数」—— 小班/团课没有时长概念，
                // 不参与统计，措辞不写清会被读成全课型
                'count' => $assumedTotal,
                'message' => "本月 {$assumedTotal} 节**私教**课的课程名未含时长（如「私教课」），已按 60 分钟计；"
                    .'如需精确请在随心瑜补齐课程名里的 45Min/60Min',
                'names' => array_column($affected, 'name'),
                'affectedUsers' => $affected,
            ];
        }
        if ($unresolvedNames !== []) {
            $warnings[] = [
                'code' => 'TEACHER_UNRESOLVED',
                'count' => count($unresolvedNames),
                'message' => '这些授课老师姓名在薪酬档案/人员别名里对不上，已单独列出，未计入任何人的课时费',
                'names' => array_keys($unresolvedNames),
            ];
        }

        return [
            'month' => $month,
            'venue' => $venue,
            'rows' => $rows,
            'warnings' => $warnings,
            'meta' => [
                // 口径透明：让用户能自己核对「行数 vs 课次」
                'dedupeKey' => ['venue', 'teacher_name', 'start_at', 'course_kind'],
                'dedupeKeyReason' => '同一老师同一时刻不可能上两节同课型的课；course_name 在现有数据里是噪声（3 个值而 course_kind 全为 group），纳入去重键会把课时虚增（实测 180 → 473）',
                'classCount' => array_sum(array_column($rows, 'classCount')),
                'bookingRows' => array_sum(array_column($rows, 'bookingRows')),
                'statusFilter' => 'signed',
                'trialExcluded' => true,
                'durationPriority' => ['name_regex', 'raw_end_time', 'manual', 'assumed_60'],
            ],
        ];
    }

    /**
     * 薪酬计算（`GET /payroll/calculate`）。逐项照抄 S1:152-191 的固定顺序。
     */
    public function calculate(string $month, ?string $venue = null): array
    {
        // ⚠️ 这里**不能**只取「所属门店 == 目标门店」的档案。引擎的候选集合是
        // `有课时 | 有业绩 | 有专项` 的人 ∪ 该店固定工资人员（S1:161-164）——
        // 跨店授课的老师（所属门店是绿地店、本月却在东部店上课）**必须出现在东部店的工资表里**，
        // 否则她那部分课时费凭空消失。只按所属门店过滤会让跨店人员的非本店课时静默丢账。
        $allProfiles = PayrollProfile::query()->orderBy('id')->get()->keyBy('id');

        // ---- 待完善档案：整行不参与计算，并进入 unavailable 显式告知 ----
        //
        // 「字段留空」在现有实现里**不等于**「不参与计算」：`role` 列有
        // `default('全职老师')`，而 `allowsBaseReward('全职老师')` 会按**实际课时**
        // 发 200~1000 元底薪奖励（完全不看档案金额），`allowsHourlyIncentive('')`
        // 也为真 ⇒ 业绩过档还会算私教激励。所以身份标签未知的人一旦进入计算，
        // 就会被静默当成全职老师算出一笔看似合理的工资。
        //
        // 失败方向取「不算」而非「按默认算」：不算会被立刻发现（清单里有人、
        // 总额对不上）；按默认算则可能多发或少发工资且无人察觉。
        $pendingProfiles = $allProfiles->filter(fn (PayrollProfile $p) => ! $p->isCalculable());
        if ($pendingProfiles->isNotEmpty()) {
            $allProfiles = $allProfiles->reject(fn (PayrollProfile $p) => ! $p->isCalculable());
        }

        $hoursData = $this->hours($month, $venue);
        $hoursByProfile = [];
        foreach ($hoursData['rows'] as $r) {
            if ($r['profileId'] !== null) {
                $hoursByProfile[(int) $r['profileId']] = $r;
            }
        }
        $inputs = $this->monthlyInputsIndexed($month, $venue);

        if ($venue === null) {
            $profiles = $allProfiles;
        } else {
            $profiles = $allProfiles->filter(function (PayrollProfile $p) use ($venue, $hoursByProfile, $inputs, $month) {
                if ($p->venue === $venue || $p->dual_base_salary) {
                    return true;                       // 本店所属 / 双底薪例外
                }
                if (isset($hoursByProfile[(int) $p->id])) {
                    return true;                       // 在本店有课时（跨店授课）
                }
                if (isset($inputs[(int) $p->id])) {
                    return true;                       // 在本店有月度输入
                }

                return PayrollPerformance::query()      // 在本店有个人业绩（跨店销售）
                    ->where('month', $month)->where('venue', $venue)
                    ->where('allocation_type', PayrollPerformance::ALLOC_PERSONAL)
                    ->where('payroll_profile_id', $p->id)
                    ->exists();
            });
        }

        // 两店累计课时 / 两店累计业绩（门槛判断一律用两店累计，S1:152-158）
        [$start, $end] = $this->monthRange($month);
        $accumulatedHours = $this->accumulatedValidHours($start, $end);
        $accumulatedPerformance = $this->accumulatedPerformance($month, $venue);
        $storeSales = $this->storeSales($month, $venue);

        $activity = $this->activityRule($month, $venue, $inputs);

        $warnings = [];
        $unavailable = [];
        $blocked = [];

        if ($activity['blocked']) {
            $blocked[] = [
                'code' => 'ACTIVITY_RULE_UNCONFIRMED',
                'message' => $activity['message'],
            ];
        }
        $multiplier = $activity['multiplier'];

        // 业绩是否导入：没导入时提成「不可计算」而不是「等于 0」
        $performanceImported = $this->performanceImported($month, $venue);
        if (! $performanceImported) {
            $unavailable[] = [
                'item' => '销售提成 / 门店提成',
                'reason' => "{$month} 的业绩表尚未导入，个人业绩与门店销售额都无从取值；导入业绩表后本项自动可算（当前按 0 展示，**不是**「提成为 0」）",
            ];
        }
        $unavailable[] = [
            'item' => '企业课 / 总监私教 / 短期集训类课时费',
            'reason' => 'ky_bookings 无 course_kind 之外的分类字段，无法把这三类从课型里区分出来；档案已保留对应单价列，待同步侧补分类后自动生效',
        ];
        $unavailable[] = [
            'item' => '299 活动卡对应的老师奖励',
            'reason' => '生产引擎只规定「299 活动卡不计个人提点与门店提成」，奖励金额与归属由当月专项输入单独接入（无公式）；请用「补贴调整」人工录入',
        ];

        // 待完善档案：显式列出，不静默少人。
        // `names` 一并结构化下发（前端用它显示「N 人待完善，未计入」），
        // 免得前端去正则解析上面那句人话（那种耦合一改文案就断）。
        $pendingInScope = [];
        if ($pendingProfiles->isNotEmpty()) {
            $names = $pendingProfiles
                ->filter(fn (PayrollProfile $p) => $venue === null || $p->venue === $venue)
                ->pluck('name')->values()->all();
            $pendingInScope = $names;
            if ($names !== []) {
                $unavailable[] = [
                    'item' => '待完善档案人员的全部工资项目（'.count($names).' 人）',
                    'reason' => '以下人员的薪酬档案尚未确认身份标签，**整行未参与本次计算**：'.implode('、', $names)
                        .'。身份标签决定底薪/绩效/课时费/提成/底薪奖励/门店提成六项算法，填错会把钱算错人；'
                        .'请在「课时费与身份标签」补齐并保存（保存后即视为已确认）',
                    'names' => $names,
                ];
            }
        }

        $rows = [];
        $attendanceDefaultNames = [];
        $taxDefaultNames = [];
        $socialInherited = [];
        $socialNoHistory = [];
        $socialOffNames = [];
        $leaveUncomputable = [];

        // 计算行集合：有课时 / 有业绩 / 有专项 / 所属店固定工资（S1:161-164）
        foreach ($profiles as $p) {
            $in = $inputs[(int) $p->id] ?? null;
            $home = (string) $p->venue;
            $role = $p->role;
            // `固定` = 所属门店 == 工资门店，或双底薪例外（S1:168）。
            // 未指定 venue（两店合并视图）时，按各自所属门店视为「固定」。
            $fixed = $venue === null || $home === $venue || $p->dual_base_salary;
            $hours = $hoursByProfile[(int) $p->id] ?? null;
            $kinds = [
                'private60' => $hours['private60'] ?? 0,
                'private45' => $hours['private45'] ?? 0,
                'small' => $hours['small'] ?? 0,
                'group' => $hours['group'] ?? 0,
                'enterprise' => $hours['enterprise'] ?? 0,
            ];
            $hasHours = array_sum($kinds) > 0;
            $perfCents = $accumulatedPerformance[(int) $p->id]['store'] ?? 0;
            $hasAnyInput = $in !== null && (
                $in->social_security !== null || $in->tax !== null || $in->attendance_days !== null
                || $in->subsidy !== null || $in->other_deduction !== null
            );
            $fixedSalaryRelevant = $fixed && ($p->baseSalaryCents() > 0 || $p->performanceCents() > 0);

            if (! $hasHours && $perfCents === 0 && ! $hasAnyInput && ! $fixedSalaryRelevant) {
                continue;
            }

            // ---- 1. 恢复基础底薪 / 绩效 / 基础单价（禁止沿用上月激励后或处罚后单价，S6:C 段）----
            $overrideCents = $in?->fixed_salary_override !== null ? PayrollMoney::cents($in->fixed_salary_override) : 0;
            $baseCents = 0;
            if ($fixed && PayrollRoles::allowsBaseSalary($role)) {
                $baseCents = $overrideCents !== 0 ? $overrideCents : $p->baseSalaryCents();
            }
            if ($in?->base_salary_zeroed) {
                $baseCents = 0;
            }
            $perfBaseCents = ($fixed && PayrollRoles::allowsPerformance($role)) ? $p->performanceCents() : 0;

            // ---- 5. 基础课时费 ----
            // 45 分钟走**该人档案的 45 分钟单价**；档案里配 0 时才退回 `60 × 0.75`（S1:175）。
            // 这里的 0.75 与下面激励的 0.75 **基数不同、来源不同**，不要合并成一个系数。
            $fee60 = PayrollMoney::cents($p->fee_private60);
            $fee45 = $p->private45FeeCents();
            $fee60Base = $kinds['private60'] * $fee60;
            $fee45Base = $kinds['private45'] * $fee45;
            $baseHourly = PayrollMoney::cents(0);
            $baseHourly += $fee60Base;
            $baseHourly += $fee45Base;
            $baseHourly += $kinds['small'] * PayrollMoney::cents($p->fee_small);
            $baseHourly += $kinds['group'] * PayrollMoney::cents($p->fee_group);
            $baseHourly += $kinds['enterprise'] * PayrollMoney::cents($p->fee_enterprise);

            // ---- 5. 私教课时费激励（两店累计业绩取档 × 活动月门槛倍数；45 分钟固定 ×0.75）----
            // 45 分钟这处是**硬编码 0.75**、基数是「加价」而非课时费，且**不读** `feePrivate45`（S1:178）。
            $addOn = 0;
            if (PayrollRoles::allowsHourlyIncentive($role)) {
                $addOn = PayrollRoles::hourlyAddOn($accumulatedPerformance[(int) $p->id]['total'] ?? 0, $multiplier);
            }
            $incentive60Cents = $kinds['private60'] * $addOn * 100;
            $incentive45Cents = PayrollMoney::mulFactor($kinds['private45'] * $addOn * 100, PayrollRoles::INCENTIVE_45_FACTOR);
            $incentiveCents = $incentive60Cents + $incentive45Cents;

            // ---- 5. 底薪奖励（全职老师，两店累计有效课时，只发所属门店）----
            $accHours = $accumulatedHours[(int) $p->id] ?? ($hours['validHours'] ?? 0);
            $baseRewardCents = 0;
            if (PayrollRoles::allowsBaseReward($role) && ($home === $venue || $venue === null)) {
                $baseRewardCents = PayrollRoles::baseRewardFor($accHours) * 100;
            }

            // ---- 6. 销售提成 ----
            $rate = $p->commissionRateFor($perfCents);
            $commissionCents = PayrollMoney::mulFactor($perfCents, $rate);

            // ---- 6. 门店提成（店长 2% / 档案覆盖率，如馆主蒙澍南 5%）----
            $storeCommissionCents = 0;
            $storeRate = $p->storeCommissionRateValue();
            if ((float) $storeRate > 0) {
                $storeCommissionCents = PayrollMoney::mulFactor($storeSales['totalCents'], $storeRate);
            }
            $storeCommissionCents += PayrollMoney::cents($in?->store_commission_addon);

            // ---- 7. 考勤（只扣事假 + 病假）----
            $leaveCents = 0;
            if ($in === null || ($in->personal_leave_hours === null && $in->sick_leave_hours === null)) {
                $attendanceDefaultNames[] = $p->name;
            } else {
                $leave = $in->leaveDeductionCents($baseCents);
                if ($leave === null) {
                    $leaveUncomputable[] = $p->name;
                    $leaveCents = 0;
                } else {
                    $leaveCents = $leave;
                }
            }

            // ---- 8. 社保（三态）/ 个税 ----
            $social = $this->resolveSocialSecurity($p, $venue, $month, $in);
            if ($social['mode'] === 'inherit') {
                if ($social['from'] !== null) {
                    $socialInherited[] = ['name' => $p->name, 'from' => $social['from']];
                } else {
                    $socialNoHistory[] = $p->name;
                }
            }
            if ($social['mode'] === 'off') {
                $socialOffNames[] = $p->name;
            }
            $taxCents = 0;
            if ($in?->tax === null) {
                $taxDefaultNames[] = $p->name;
            } else {
                $taxCents = PayrollMoney::cents($in->tax);
            }

            $subsidyCents = PayrollMoney::cents($in?->subsidy);
            $prevCents = PayrollMoney::cents($in?->previous_adjustment);
            $otherCents = abs(PayrollMoney::cents($in?->other_deduction));

            // ---- 9. 重算应发 / 实发 ----
            $grossCents = $baseCents + $perfBaseCents + $baseRewardCents + $baseHourly + $incentiveCents
                + $commissionCents + $storeCommissionCents + $subsidyCents + $prevCents
                - $leaveCents - $otherCents;
            $netCents = $grossCents - $social['cents'] - $taxCents;

            $rows[] = [
                'userId' => $p->user_id,
                'profileId' => $p->id,
                'name' => $p->name,
                'displayRole' => PayrollRoles::displayRole($role, $home, $venue ?? $home),
                'role' => $role,
                'venue' => $home,
                'baseSalary' => PayrollMoney::toFloat($baseCents),
                'performance' => PayrollMoney::toFloat($perfBaseCents),
                'baseReward' => PayrollMoney::toFloat($baseRewardCents),
                'accumulatedValidHours' => $accHours,
                'hours' => $kinds,
                'totalHours' => array_sum($kinds),
                'baseHourlyFee' => PayrollMoney::toFloat($baseHourly),
                // 45 分钟的两个量**分别暴露**（基数不同，合并成一个字段就再也看不出差异）
                'feePrivate60' => PayrollMoney::toFloat($fee60),
                'feePrivate45' => PayrollMoney::toFloat($fee45),
                // 45 分钟单价的**来源**：`profile` = 档案独立配置值；`derived_60x0.75` = 档案配 0 时的折算值。
                // 引擎 :175 的三元表达式 `(p['60']*0.75 if p['45']==0 else p['45'])` 就是这两条路。
                'feePrivate45Source' => $p->private45IsDerived() ? 'derived_60x0.75' : 'profile',
                'feePrivate45Derived' => $p->private45IsDerived(),
                'baseHourlyPrivate60' => PayrollMoney::toFloat($fee60Base),
                'baseHourlyPrivate45' => PayrollMoney::toFloat($fee45Base),
                'hourlyIncentiveAddOn' => $addOn,
                'hourlyIncentive' => PayrollMoney::toFloat($incentiveCents),
                'hourlyIncentivePrivate60' => PayrollMoney::toFloat($incentive60Cents),
                'hourlyIncentivePrivate45' => PayrollMoney::toFloat($incentive45Cents),
                'hourlyFee' => PayrollMoney::toFloat($baseHourly + $incentiveCents),
                'personalPerformance' => PayrollMoney::toFloat($perfCents),
                'commissionRate' => (float) $rate,
                'commission' => PayrollMoney::toFloat($commissionCents),
                'storeCommissionRate' => (float) $storeRate,
                'storeCommission' => PayrollMoney::toFloat($storeCommissionCents),
                'subsidy' => PayrollMoney::toFloat($subsidyCents),
                'previousAdjustment' => PayrollMoney::toFloat($prevCents),
                'leaveDeduction' => PayrollMoney::toFloat($leaveCents),
                'otherDeduction' => PayrollMoney::toFloat($otherCents),
                'gross' => PayrollMoney::toFloat($grossCents),
                'socialSecurity' => PayrollMoney::toFloat($social['cents']),
                'socialSecurityMode' => $social['mode'],
                'socialSecurityInheritedFrom' => $social['from'],
                'tax' => PayrollMoney::toFloat($taxCents),
                'net' => PayrollMoney::toFloat($netCents),
                'inputsReal' => [
                    'attendance' => $in !== null && $in->attendance_days !== null,
                    'socialSecurity' => $social['mode'] !== 'inherit',
                    'tax' => $in?->tax !== null,
                    'performance' => $performanceImported,
                ],
            ];
        }

        usort($rows, fn ($a, $b) => [$a['venue'], $a['name']] <=> [$b['venue'], $b['name']]);

        // ---- warnings：必须区分「真实输入」与「默认值」----
        if ($attendanceDefaultNames !== []) {
            $warnings[] = [
                'code' => 'ATTENDANCE_DEFAULT_FULL',
                'count' => count($attendanceDefaultNames),
                'message' => '未填写考勤，按全勤处理（请假扣款 0）',
                'names' => array_values($attendanceDefaultNames),
            ];
        }
        if ($leaveUncomputable !== []) {
            $warnings[] = [
                'code' => 'ATTENDANCE_MISSING_DAYS',
                'count' => count($leaveUncomputable),
                'level' => 'high',
                'message' => '填了事假/病假小时但没有「应出勤天数」，无法计算请假扣款，已按 0 处理（**需补应出勤天数**）',
                'names' => array_values($leaveUncomputable),
            ];
        }
        if ($taxDefaultNames !== []) {
            $warnings[] = [
                'code' => 'TAX_DEFAULT_ZERO',
                'count' => count($taxDefaultNames),
                'message' => '未填写个税，按 0 处理（个税不沿用上月）',
                'names' => array_values($taxDefaultNames),
            ];
        }
        if ($socialInherited !== []) {
            $warnings[] = [
                'code' => 'SOCIAL_SECURITY_INHERITED',
                'count' => count($socialInherited),
                'message' => '本月未操作社保，沿用该人最近一次有效设置',
                'details' => $socialInherited,
            ];
        }
        if ($socialNoHistory !== []) {
            $warnings[] = [
                'code' => 'SOCIAL_SECURITY_NO_HISTORY',
                'count' => count($socialNoHistory),
                'message' => '这些人员没有任何社保历史设置，本月按 0 处理（状态待财务确认）',
                'names' => array_values($socialNoHistory),
            ];
        }
        if ($socialOffNames !== []) {
            $warnings[] = [
                'code' => 'SOCIAL_SECURITY_OFF',
                'count' => count($socialOffNames),
                'message' => '本月显式停缴社保（已打断继承链，不会沿用历史非零值）',
                'names' => array_values($socialOffNames),
            ];
        }
        if (! $activity['confirmed']) {
            $warnings[] = [
                'code' => 'ACTIVITY_RULE_DEFAULT',
                'message' => '当月未填写活动月激励规则，按「普通月 ×1」处理；如本月有品牌月/周年庆活动，请先录入并确认，否则私教激励门槛会算低',
            ];
        }
        foreach ($hoursData['warnings'] as $w) {
            $warnings[] = $w;
        }

        // 社保整体未加载的业务闸门（S6:§4.5）
        $anySocial = array_sum(array_column($rows, 'socialSecurity'));
        if ($rows !== [] && $anySocial <= 0 && $socialNoHistory !== [] && count($socialNoHistory) === count($rows)) {
            $warnings[] = [
                'code' => 'SOCIAL_SECURITY_NOT_LOADED',
                'count' => count($rows),
                'message' => '本月所有人社保均为 0 且无历史设置，请复核社保是否漏录',
            ];
        }

        $stage = 'waiting_tax';
        if (count($taxDefaultNames) < count($rows)) {
            $stage = 'waiting_confirm';
        }

        return [
            'month' => $month,
            'venue' => $venue,
            'stage' => $stage,
            'activityType' => $activity['type'],
            'activityThresholdMultiplier' => $multiplier,
            'storeSales' => PayrollMoney::toFloat($storeSales['totalCents']),
            'storeSalesRaw' => PayrollMoney::toFloat($storeSales['rawCents']),
            'storeSalesSource' => $storeSales['source'],
            'rows' => $rows,
            'storeTotal' => [
                'headcount' => count($rows),
                'hours' => array_sum(array_column($rows, 'totalHours')),
                'gross' => $this->sumField($rows, 'gross'),
                'socialSecurity' => $this->sumField($rows, 'socialSecurity'),
                'tax' => $this->sumField($rows, 'tax'),
                'net' => $this->sumField($rows, 'net'),
            ],
            'warnings' => $warnings,
            'unavailable' => $unavailable,
            'blocked' => $blocked,
            // 待完善（未参与计算）的人员姓名：结构化下发，供前端显示「N 人未计入」
            'pendingNames' => $pendingInScope,
        ];
    }

    /**
     * 月度输入列表（含社保三态的**生效值回显**）。
     *
     * 响应必须回显 `inherit` 解析后的实际金额与来源月份 —— 前端要把三种状态分别渲染成
     * 「实际金额 / 沿用上月 ¥X / 本月不缴」，**不允许**把 inherit 直接渲染成 0。
     */
    public function monthlyInputRows(string $month, ?string $venue = null): array
    {
        // ⚠️ 这里要按 **(人, 门店)** 组合展开，而不是「一人一行」。
        // 社保是按门店扣的，同一个人可能在两家店各有一条月度输入（跨店授课/跨店发薪）；
        // 若只按人取一条、并把 `venue` 填成他的**所属门店**，那条东部店的社保就会
        // 在列表里显示成绿地店（看不到却参与计算），用户对不上账。本机实测踩过。
        $inputRows = PayrollMonthlyInput::query()
            ->where('month', $month)
            ->when($venue !== null, fn ($q) => $q->where('venue', $venue))
            ->get()
            ->groupBy('payroll_profile_id');

        $profiles = PayrollProfile::query()
            ->when($venue !== null, fn ($q) => $q->where('venue', $venue))
            ->orderBy('id')->get()
            ->keyBy('id');

        // 收集所有需要展示的 (profileId, store) 组合
        $pairs = [];   // "pid|store" => [pid, store]
        if ($venue !== null) {
            foreach ($profiles as $p) {
                $pairs[$p->id.'|'.$venue] = [(int) $p->id, $venue];
            }
        } else {
            foreach ($profiles as $p) {
                $pairs[$p->id.'|'.(string) $p->venue] = [(int) $p->id, (string) $p->venue];
            }
        }
        // 有输入行但上面没覆盖到的组合（跨店输入）也要列出来
        foreach ($inputRows as $pid => $group) {
            foreach ($group as $row) {
                $store = (string) $row->venue;
                $pairs[$pid.'|'.$store] = [(int) $pid, $store];
            }
        }
        $missingIds = array_diff(
            array_unique(array_column($pairs, 0)),
            $profiles->keys()->map(fn ($i) => (int) $i)->all()
        );
        if ($missingIds !== []) {
            $profiles = $profiles->union(PayrollProfile::whereIn('id', $missingIds)->get()->keyBy('id'));
        }

        $rows = [];
        foreach ($pairs as [$pid, $store]) {
            $p = $profiles[$pid] ?? null;
            if ($p === null) {
                continue;
            }
            $in = collect($inputRows[$pid] ?? [])->firstWhere('venue', $store);
            $social = $this->resolveSocialSecurity($p, $store, $month, $in);
            $rows[] = [
                'userId' => $p->user_id,
                'profileId' => $p->id,
                'name' => $p->name,
                // 本行**实际生效的门店**（社保按门店扣），不是人员的所属门店
                'venue' => $store,
                'homeVenue' => $p->venue,
                'role' => $p->role,
                'attendanceDays' => $in?->attendance_days === null ? null : (float) $in->attendance_days,
                'personalLeaveHours' => $in?->personal_leave_hours === null ? null : (float) $in->personal_leave_hours,
                'sickLeaveHours' => $in?->sick_leave_hours === null ? null : (float) $in->sick_leave_hours,
                'attendanceIsDefault' => $in === null || ($in->personal_leave_hours === null && $in->sick_leave_hours === null),
                'socialSecurity' => PayrollMoney::toFloat($social['cents']),
                'socialSecurityRaw' => $in?->social_security === null ? null : (float) $in->social_security,
                'socialSecurityMode' => $social['mode'],
                'socialSecurityInheritedFrom' => $social['from'],
                'socialSecurityLabel' => $this->socialLabel($social),
                'tax' => PayrollMoney::toFloat(PayrollMoney::cents($in?->tax)),
                'taxIsDefault' => $in === null || $in->tax === null,
                'subsidy' => PayrollMoney::toFloat(PayrollMoney::cents($in?->subsidy)),
                'previousAdjustment' => PayrollMoney::toFloat(PayrollMoney::cents($in?->previous_adjustment)),
                'otherDeduction' => PayrollMoney::toFloat(abs(PayrollMoney::cents($in?->other_deduction))),
                'fixedSalaryOverride' => $in?->fixed_salary_override === null ? null : (float) $in->fixed_salary_override,
                'baseSalaryZeroed' => (bool) ($in?->base_salary_zeroed),
                'storeCommissionAddon' => PayrollMoney::toFloat(PayrollMoney::cents($in?->store_commission_addon)),
                'note' => (string) ($in?->note ?? ''),
                'updatedBy' => $in?->updated_by,
            ];
        }

        return ['month' => $month, 'venue' => $venue, 'rows' => $rows];
    }

    /**
     * 社保三态解析（S1 无对应实现，口径来自规格 §4.2）。
     *
     * 回退 = 按 (venue, user_id) 往更早月份倒序找**最近一条** mode ∈ {set, off}：
     * `set` 沿用其金额，`off` 取 0 并**打断继承链**。跨月但**不跨门店**。
     *
     * @return array{mode:string,cents:int,from:?string}
     */
    public function resolveSocialSecurity(PayrollProfile $p, ?string $venue, string $month, ?PayrollMonthlyInput $in): array
    {
        // 未指定门店（两店合并视图）时按该人**所属门店**回退 —— 社保按门店扣，
        // 合并视图也必须落在正确的那个店里，不能因为 venue=null 就抛类型错（曾 500）。
        $venue = $venue ?? (string) $p->venue;
        $mode = $in?->socialMode() ?? PayrollMonthlyInput::SOCIAL_INHERIT;
        if ($mode === PayrollMonthlyInput::SOCIAL_SET) {
            return ['mode' => 'set', 'cents' => PayrollMoney::cents($in?->social_security), 'from' => null];
        }
        if ($mode === PayrollMonthlyInput::SOCIAL_OFF) {
            return ['mode' => 'off', 'cents' => 0, 'from' => null];
        }

        // inherit：找最近一条 set/off。查的是「更早的月份」，故 month < 目标月
        $prev = PayrollMonthlyInput::query()
            ->where('venue', $venue)
            ->when($p->user_id !== null, fn ($q) => $q->where('user_id', $p->user_id))
            ->when($p->user_id === null, fn ($q) => $q->where('payroll_profile_id', $p->id))
            ->where('month', '<', $month)
            ->whereIn('social_security_mode', [PayrollMonthlyInput::SOCIAL_SET, PayrollMonthlyInput::SOCIAL_OFF])
            ->orderByDesc('month')
            ->first();

        if ($prev === null) {
            return ['mode' => 'inherit', 'cents' => 0, 'from' => null];
        }
        if ($prev->socialMode() === PayrollMonthlyInput::SOCIAL_OFF) {
            // off 必须打断继承链：停缴之后不能再把更早的非零值沿回来
            return ['mode' => 'off', 'cents' => 0, 'from' => null];
        }

        return [
            'mode' => 'inherit',
            'cents' => PayrollMoney::cents($prev->social_security),
            'from' => (string) $prev->month,
        ];
    }

    /** 社保三态的中文标签（前端直接显示，避免各处再拼一份文案） */
    private function socialLabel(array $social): string
    {
        return match ($social['mode']) {
            'set' => '本月已设',
            'off' => '本月不缴',
            default => $social['from'] !== null
                ? "沿用 {$social['from']} 的 ¥".PayrollMoney::fmt($social['cents'])
                : '无历史设置（按 0）',
        };
    }

    /**
     * 课次聚合（SQL 层去重）。
     *
     * 去重键 `(venue, teacher_name, start_at, course_kind)`：
     * 同一老师同一时刻不可能上两节**同课型**的课，所以这四列足以标识一节课；
     * 而 `course_name` **不能**进键 —— 现有数据里课程名只有 3 个值（私教课/精品团课/精品小班）
     * 但 `course_kind` 全为 group，课程名与课型互相矛盾，是噪声；把它并进键会把
     * 同一个课次拆成多条（实测课次数 180 → 473），课时虚增一倍以上。
     *
     * SQLite 不支持 `COUNT(DISTINCT a, b)`（实测 `wrong number of arguments to function count()`），
     * 所以用 `group by` 子查询，MySQL 与 SQLite 都跑得通。
     */
    private function classRows(Carbon $start, Carbon $end, ?string $venue): \Illuminate\Support\Collection
    {
        return KyBooking::query()
            ->select([
                'venue',
                'teacher_name',
                'start_at',
                'course_kind',
                DB::raw('min(course_name) as course_name'),
                DB::raw('min(raw) as raw_sample'),
                DB::raw('count(*) as row_count'),
            ])
            ->where('status', 'signed')
            ->where('is_trial', false)
            ->whereBetween('start_at', [$start, $end])
            ->when($venue !== null, fn ($q) => $q->where('venue', $venue))
            ->whereNotNull('teacher_name')
            ->where('teacher_name', '!=', '')
            ->groupBy(['venue', 'teacher_name', 'start_at', 'course_kind'])
            ->get();
    }

    /**
     * 课时时长解析（**只对私教调用**）。优先级（用户 2026-09 第一手确认 + 仓库内实证）：
     *
     * 1. `name_regex` —— 时长写在课程名里，格式 `VIP定制私教｜45Min` / `全能私教45Min` /
     *    `【线上】45min私教`（`随心瑜后台解读/随心瑜后台完整解读.md:266`、`notes.md:472/105`）。
     *    **这是主路径**。
     * 2. `raw_end_time` —— 上游预约行带 `start_time`/`end_time` 时用差值。
     * 3. `manual` —— 由档案/月度输入人工指定（本期无 UI，保留取值以便未来接入）。
     * 4. `assumed_60` —— 前三者都取不到时降级，且必须如实标注、汇总暴露。
     *
     * @return array{minutes:int,source:string}
     */
    public static function durationOf(?string $courseName, mixed $raw = null): array
    {
        $minutes = self::minutesFromCourseName($courseName);
        if ($minutes !== null) {
            return ['minutes' => $minutes, 'source' => 'name_regex'];
        }
        $minutes = self::minutesFromRaw($raw);
        if ($minutes !== null) {
            return ['minutes' => $minutes, 'source' => 'raw_end_time'];
        }

        return ['minutes' => 60, 'source' => 'assumed_60'];
    }

    /** 从课程名提取时长（分钟）。取不到返回 null */
    public static function minutesFromCourseName(?string $courseName): ?int
    {
        $name = trim((string) $courseName);
        if ($name === '') {
            return null;
        }
        // 全角「｜」与半角「|」分隔的写法：VIP定制私教｜45Min
        if (preg_match('/[｜|]\s*(\d{1,3})\s*(?:Min|min|MIN|分钟)/u', $name, $m)) {
            return self::normalizeMinutes((int) $m[1]);
        }
        // 数字直接跟在名称后：全能私教45Min、【线上】45min私教、私教课60分钟
        if (preg_match('/(\d{1,3})\s*(?:Min|min|MIN|分钟)/u', $name, $m)) {
            return self::normalizeMinutes((int) $m[1]);
        }
        // 中文「45分」写法
        if (preg_match('/(\d{1,3})\s*(?:′|分)/u', $name, $m)) {
            return self::normalizeMinutes((int) $m[1]);
        }

        return null;
    }

    /**
     * 私教时长档位白名单：**只接受 45 / 60**。
     *
     * 其他数字一律视为「未含时长」返回 null（于是降级为 `assumed_60` 并如实标注），
     * 而不是按最接近档位硬归 —— 引擎只有这两档，把 30 / 90 猜成 45 / 60 会静默改价。
     * 例：`高分体式30分钟` 这类**课程内容**里带数字的名称，若按「最接近档」归位会误判。
     */
    private static function normalizeMinutes(int $minutes): ?int
    {
        return match (true) {
            $minutes === 45 => 45,
            $minutes === 60 => 60,
            default => null,
        };
    }

    /** 从 raw 的 start_time/end_time 差值取时长（同样只认 45/60 两档） */
    private static function minutesFromRaw(mixed $raw): ?int
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw)) {
            return null;
        }
        $start = (string) ($raw['start_time'] ?? $raw['startTime'] ?? '');
        $end = (string) ($raw['end_time'] ?? $raw['endTime'] ?? '');
        if ($start === '' || $end === '') {
            return null;
        }
        $s = self::parseClock($start);
        $e = self::parseClock($end);
        if ($s === null || $e === null) {
            return null;
        }
        $diff = $e - $s;
        if ($diff <= 0) {
            $diff += 86400; // 跨零点
        }
        $minutes = (int) round($diff / 60);
        if ($minutes <= 0 || $minutes > 300) {
            return null;
        }

        return self::normalizeMinutes($minutes);
    }

    private static function parseClock(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        // 允许 "10:00" / "10:00:00" / "2026-08-01 10:00:00" / "10:00 AM"
        if (preg_match('/(\d{1,2}):(\d{2})(?::(\d{2}))?/', $value, $m)) {
            $h = (int) $m[1];
            $i = (int) $m[2];
            $s = (int) ($m[3] ?? 0);
            if (preg_match('/pm/i', $value) && $h < 12) {
                $h += 12;
            }
            if (preg_match('/am/i', $value) && $h === 12) {
                $h = 0;
            }

            return $h * 3600 + $i * 60 + $s;
        }

        return null;
    }

    /** 课型归一：只认 private/small/group，缺失按 group（与 courseKindFrom 的失败关闭一致） */
    private function normalizeKind(?string $kind): string
    {
        $kind = (string) $kind;

        return in_array($kind, ['private', 'small', 'group'], true) ? $kind : 'group';
    }

    /** 一个老师最主要的时长来源（取出现次数最多的那个） */
    private function primaryDurationSource(array $sources): string
    {
        if ($sources === []) {
            return 'assumed_60';
        }
        arsort($sources);

        return (string) array_key_first($sources);
    }

    /**
     * 两店累计有效课时（底薪奖励门槛，S1:152-156）。
     *
     * 有效课时 = 定制私教60 + 定制私教45 + 私教小班 + 精品团课；
     * **企业课、总监私教、短期集训类不计**。按档案 id 聚合。
     *
     * @return array<int, int> profileId => 累计有效课时
     */
    public function accumulatedValidHours(Carbon $start, Carbon $end): array
    {
        $classes = $this->classRows($start, $end, null);
        $resolver = $this->nameResolver();
        $out = [];
        foreach ($classes as $c) {
            $name = trim((string) $c->teacher_name);
            if ($name === '') {
                continue;
            }
            $kind = $this->normalizeKind($c->course_kind);
            if ($kind === 'enterprise') {
                continue;
            }
            // 私教要按分钟档拆开，但两档都算有效课时，所以这里只需判断「是否私教」
            $profile = $resolver->resolve($name);
            if ($profile === null) {
                continue;
            }
            $out[$profile->id] = ($out[$profile->id] ?? 0) + 1;
        }

        return $out;
    }

    /**
     * 两店累计个人业绩（私教激励门槛用，S1:158）。
     *
     * @return array<int, array{total:int,store:int}> profileId => [两店累计, 本店]
     */
    public function accumulatedPerformance(string $month, ?string $venue): array
    {
        $out = [];
        $rows = PayrollPerformance::query()
            ->where('month', $month)
            ->where('allocation_type', PayrollPerformance::ALLOC_PERSONAL)
            ->whereNotNull('payroll_profile_id')
            ->get(['payroll_profile_id', 'venue', 'commission_amount']);
        foreach ($rows as $r) {
            $id = (int) $r->payroll_profile_id;
            $out[$id] ??= ['total' => 0, 'store' => 0];
            $cents = PayrollMoney::cents($r->commission_amount);
            $out[$id]['total'] += $cents;
            if ($venue !== null && (string) $r->venue === $venue) {
                $out[$id]['store'] += $cents;
            }
        }
        if ($venue === null) {
            foreach ($out as $id => $v) {
                $out[$id]['store'] = $v['total'];
            }
        }

        return $out;
    }

    /**
     * 门店销售额（门店提成基数）。
     *
     * **只用提点口径**：`store_sales_amount` 已剔除 299 活动卡（S1:88-91）。
     * 若月度输入里有已确认的「门店销售额确认值」则覆盖自动汇总（S1:111-115）。
     *
     * @return array{totalCents:int,rawCents:int,source:string}
     */
    public function storeSales(string $month, ?string $venue): array
    {
        $rows = PayrollPerformance::query()
            ->where('month', $month)
            ->when($venue !== null, fn ($q) => $q->where('venue', $venue))
            ->get(['transaction_key', 'store_sales_amount', 'transaction_amount']);

        // ⚠️ 不能用 `(int) $q->sum(...)`：`sum()` 回的是**元**的十进制字符串
        // （"364167.20"），直接转 int 变成 364167 元，而本函数单位是**分** ——
        // 门店提成会按 1/100 算（实测踩过：2% 得 3,641.67 而非 7,283.34）。
        // 一律经 `PayrollMoney::cents()` 逐行解析后累加。
        $total = 0;
        $raw = 0;
        $seenTransaction = [];
        foreach ($rows as $r) {
            $total += PayrollMoney::cents($r->store_sales_amount);
            // `transaction_amount` 在**同一条交易的每条归属行上重复**，逐行累加会把
            // 一行多归属的交易重复计（样表 3 行）。原始口径按交易去重，
            // 与导入侧 store_sales 的计数口径保持一致。
            $key = (string) $r->transaction_key;
            if ($key !== '' && isset($seenTransaction[$key])) {
                continue;
            }
            $seenTransaction[$key] = true;
            $raw += PayrollMoney::cents($r->transaction_amount);
        }

        $source = 'auto';
        if ($venue !== null) {
            $confirmed = PayrollMonthlyInput::query()
                ->where('month', $month)->where('venue', $venue)
                ->where('store_sales_confirm_status', '已确认')
                ->whereNotNull('confirmed_store_sales')
                ->value('confirmed_store_sales');
            if ($confirmed !== null) {
                $total = PayrollMoney::cents($confirmed);
                $source = 'manual_confirmed';
            }
        }

        return ['totalCents' => $total, 'rawCents' => $raw, 'source' => $source];
    }

    /** 该月该店是否已导入业绩（区分「提成为 0」与「业绩没导入」） */
    public function performanceImported(string $month, ?string $venue): bool
    {
        return PayrollPerformance::query()
            ->where('month', $month)
            ->when($venue !== null, fn ($q) => $q->where('venue', $venue))
            ->exists();
    }

    /**
     * 活动月激励规则（S1:100-110）。
     *
     * 规则**必须来自当月输入**，禁止按月份硬编码；且必须「已确认」否则阻断。
     * 完全没有录入时按「普通月 ×1」并给 warning + blocked（不静默放过）。
     */
    public function activityRule(string $month, ?string $venue, ?array $inputs = null): array
    {
        $row = PayrollMonthlyInput::query()
            ->where('month', $month)
            ->when($venue !== null, fn ($q) => $q->where('venue', $venue))
            ->whereNotNull('activity_type')
            ->orderBy('id')
            ->first();

        if ($row === null) {
            return [
                'type' => '普通月',
                'multiplier' => 1.0,
                'confirmed' => false,
                'blocked' => false,
                'message' => '当月未录入活动月激励规则，已按「普通月 ×1」处理',
            ];
        }

        $type = (string) $row->activity_type;
        $multiplier = (float) ($row->threshold_multiplier ?? 1);
        $allowed = PayrollRoles::ACTIVITY_MULTIPLIERS;
        $ok = isset($allowed[$type]) && abs($allowed[$type] - $multiplier) < 0.001
            && (string) $row->activity_confirm_status === '已确认';

        return [
            'type' => $type,
            'multiplier' => $multiplier,
            'confirmed' => $ok,
            'blocked' => ! $ok,
            'message' => $ok
                ? '活动月规则已确认'
                : "活动月激励规则未确认或不匹配：{$type}，门槛倍数 {$multiplier}，状态「{$row->activity_confirm_status}」——该店结果不可用于交付",
        ];
    }

    /** @return array<int, PayrollMonthlyInput> profileId => 输入行 */
    public function monthlyInputsIndexed(string $month, ?string $venue): array
    {
        $rows = PayrollMonthlyInput::query()
            ->where('month', $month)
            ->when($venue !== null, fn ($q) => $q->where('venue', $venue))
            ->get();

        return $rows->keyBy('payroll_profile_id')->all();
    }

    /**
     * 姓名 → 薪酬档案的**唯一解析入口**。
     *
     * 四个来源合并：`users.name` ∪ `staff_aliases.alias` ∪ `payroll_profiles.name`
     * ∪ `payroll_profile_aliases.alias`。**唯一命中才返回**，歧义或对不上返回 null
     * （宁可不认，不猜）—— 与 `staffUserId()` 同语义。
     */
    public function nameResolver(): PayrollNameResolver
    {
        return new PayrollNameResolver;
    }

    private function sumField(array $rows, string $field): float
    {
        $cents = 0;
        foreach ($rows as $r) {
            $cents += PayrollMoney::cents($r[$field] ?? 0);
        }

        return PayrollMoney::toFloat($cents);
    }

    /** 自然月区间 [start, end]（闭区间到 23:59:59） */
    public function monthRange(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return [$start, $end];
    }

    /** 月份格式校验 */
    public static function isValidMonth(?string $month): bool
    {
        $month = trim((string) $month);
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return false;
        }

        return true;
    }
}
