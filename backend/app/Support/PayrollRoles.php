<?php

namespace App\Support;

/**
 * 薪酬身份标签（工资岗位）—— **唯一枚举与唯一定义处**。
 *
 * ## 与 `users.roles` 的区别（不要混用）
 *
 * `users.roles` 是**系统权限角色**（`R_SUPER/R_MANAGER/R_SERVICE/R_TEACHER/R_MEDIA`），
 * 决定能看什么数据；本枚举是**工资岗位**，决定钱怎么算。两者语义无关，
 * 人员管理页的「角色」改动**不得**影响工资计算（规格 §5.2）。
 *
 * ## 口径来源
 *
 * 生产引擎 `统一工资计算引擎.py:168-191`（唯一实现），逐标签的六项算法见规格 §2.2。
 * 本类把该实现翻译成 PHP，**PayrollService 只允许通过本类的判定方法取口径**，
 * 不允许在别处再写一份 `if role === '兼职老师'`（否则就会出现第二套口径）。
 *
 * ## 存储层枚举 vs 展示层标签
 *
 * 引擎 `岗位显示()`（S1:46-50）会改写展示标签（`馆主→管理层`、
 * 跨店 `全职老师/顾问→{所属门店}全职老师`）。**展示标签绝不回写档案**，
 * 否则 `馆主` 存成 `管理层` 之后 `role === '馆主'` 的判定全部失效。
 */
final class PayrollRoles
{
    /** 门店枚举（`ky_bookings.venue` 实测仅此两值） */
    public const VENUES = ['东部店', '绿地店'];

    /** 身份标签存储层枚举（顺序即展示顺序，规格 §2.1） */
    public const ROLES = [
        '馆主',
        '店长',
        '全职老师',
        '专职老师',
        '兼职老师',
        '顾问',
        '新媒体',
        '保洁',
        '固定发放',
        '已离职',
        '离职结算',
    ];

    /** 人员状态枚举 */
    public const STATUSES = ['有效', '已离职'];

    /** 双底薪例外白名单（主档「特殊规则」sheet，规则类型 `dual_base_salary`，实测 3 人） */
    public const DUAL_BASE_SALARY_WHITELIST = ['蒙澍南', '谭婷婷', '张卫玉'];

    /** 底薪奖励档位（S1:39-45，两店累计**有效课时**） */
    public const BASE_REWARD_TIERS = [
        ['hours' => 120, 'amount' => 1000],
        ['hours' => 110, 'amount' => 800],
        ['hours' => 100, 'amount' => 400],
        ['hours' => 80, 'amount' => 200],
    ];

    /** 私教课时费激励档位（S1:29-37；门槛会被活动月倍数放大） */
    public const HOURLY_INCENTIVE_TIERS = [
        ['threshold' => 100000, 'addOn' => 40],
        ['threshold' => 80000, 'addOn' => 35],
        ['threshold' => 70000, 'addOn' => 25],
        ['threshold' => 50000, 'addOn' => 15],
        ['threshold' => 30000, 'addOn' => 10],
    ];

    /** 销售提成阶梯（S1:24-28；全额阶梯，不做超额累进） */
    public const COMMISSION_TIERS = [
        ['threshold' => 60000, 'rate' => 0.07],
        ['threshold' => 30000, 'rate' => 0.04],
        ['threshold' => 0, 'rate' => 0.02],
    ];

    /** 活动月门槛倍数（S1:108-110；**倍数只放大门槛，不放大实际业绩**） */
    public const ACTIVITY_MULTIPLIERS = [
        '普通月' => 1.0,
        '品牌月' => 2.0,
        '周年庆' => 2.5,
    ];

    /**
     * 🔴 45 分钟档的**两个 0.75 不是同一个东西**，故拆成两个常量，禁止合并。
     *
     * ```python
     * # S1:175 基础课时费 —— 45 分钟走「该人 45 分钟单价」，基数 = 课时费（约 120 元/节）
     * 基础 = … + hs['定制私教45分钟'] * (p['60']*0.75 if p['45']==0 else p['45'])
     *
     * # S1:178 私教激励 —— 45 分钟**硬编码** ×0.75，基数 = 加价（约 10~40 元/节）
     * 激励 = hs['定制私教60分钟']*加价 + hs['定制私教45分钟']*加价*0.75
     * ```
     *
     * 两处乘数都是 0.75，但**基数完全不同**（课时费 vs 加价，差 3~12 倍），
     * 而且激励那处**不读** `p['45']`。曾经用同一个常量表示这两件事，
     * 一旦有人「顺手统一」，激励金额就会按课时费的折算口径去算，直接算错工资。
     */
    public const FEE_45_FALLBACK_FACTOR = '0.75';

    /** 私教激励 45 分钟档的固定系数（S1:178，硬编码，与人员单价无关） */
    public const INCENTIVE_45_FACTOR = '0.75';

    /** 有效课时口径：只有这四种计入底薪奖励（S1:156） */
    public const BASE_REWARD_KINDS = ['private60', 'private45', 'small', 'group'];

    /** 六项算法说明（GET /payroll/roles 下发，前端禁止再写一份） */
    private const RULES = [
        '馆主' => [
            'base' => '固定门店（或双底薪例外）时取档案底薪，可被「固定薪资折算」覆盖',
            'performance' => '固定门店时取档案绩效',
            'hourly' => '档案单价 × 各课型课时',
            'hourlyIncentive' => '享私教课时费激励（按两店累计业绩取档）',
            'commission' => '普通阶梯：≥6万 7% / ≥3万 4% / 否则 2%（全额，不累进）',
            'baseReward' => '无（底薪奖励仅全职老师）',
            'storeCommission' => '无（例外：馆主蒙澍南按档案的门店提成率 5%）',
        ],
        '店长' => [
            'base' => '固定门店时取档案底薪',
            'performance' => '固定门店时取档案绩效',
            'hourly' => '档案单价 × 各课型课时',
            'hourlyIncentive' => '享私教课时费激励',
            'commission' => '普通阶梯（店长本人销售同样按阶梯计提）',
            'baseReward' => '无',
            'storeCommission' => '本店全店销售额 × 2%',
        ],
        '全职老师' => [
            'base' => '固定门店时取档案底薪',
            'performance' => '固定门店时取档案绩效',
            'hourly' => '档案单价 × 各课型课时',
            'hourlyIncentive' => '享私教课时费激励',
            'commission' => '普通阶梯',
            'baseReward' => '两店累计有效课时 80/100/110/120 → 200/400/800/1000，且只发在所属门店',
            'storeCommission' => '无',
        ],
        '专职老师' => [
            'base' => '固定门店时取档案底薪（主档实测均为 0）',
            'performance' => '固定门店时取档案绩效',
            'hourly' => '档案单价 × 各课型课时',
            'hourlyIncentive' => '**不享**私教课时费激励',
            'commission' => '**固定 7%**（不走阶梯）',
            'baseReward' => '无',
            'storeCommission' => '无',
        ],
        '兼职老师' => [
            'base' => '**强制 0**（不受档案底薪与「固定薪资折算」影响）',
            'performance' => '**强制 0**',
            'hourly' => '档案单价 × 实际发生的课时（只留实际发生项）',
            'hourlyIncentive' => '享私教课时费激励',
            'commission' => '普通阶梯',
            'baseReward' => '无',
            'storeCommission' => '无',
        ],
        '顾问' => [
            'base' => '固定门店时取档案底薪',
            'performance' => '固定门店时取档案绩效',
            'hourly' => '档案单价 × 课时（主档实测单价为 0，顾问不上课）',
            'hourlyIncentive' => '享私教课时费激励',
            'commission' => '普通阶梯',
            'baseReward' => '无',
            'storeCommission' => '无',
        ],
        '新媒体' => [
            'base' => '固定门店时取档案底薪（双底薪例外两店各发）',
            'performance' => '固定门店时取档案绩效（主档实测 0）',
            'hourly' => '档案单价 × 课时（主档实测 0）',
            'hourlyIncentive' => '享私教课时费激励',
            'commission' => '普通阶梯',
            'baseReward' => '无',
            'storeCommission' => '无',
        ],
        '保洁' => [
            'base' => '固定门店时取档案底薪',
            'performance' => '固定门店时取档案绩效（主档实测 0）',
            'hourly' => '无课时费',
            'hourlyIncentive' => '无',
            'commission' => '无（无销售业绩）',
            'baseReward' => '无',
            'storeCommission' => '无',
        ],
        '固定发放' => [
            'base' => '固定门店时取档案底薪',
            'performance' => '固定门店时取档案绩效（主档实测 0）',
            'hourly' => '无课时费',
            'hourlyIncentive' => '无',
            'commission' => '普通阶梯',
            'baseReward' => '无',
            'storeCommission' => '无',
        ],
        '已离职' => [
            'base' => '固定门店时取档案底薪；当月按「固定薪资折算」覆盖',
            'performance' => '同上',
            'hourly' => '按实际课时',
            'hourlyIncentive' => '享私教课时费激励',
            'commission' => '普通阶梯',
            'baseReward' => '无',
            'storeCommission' => '无',
        ],
        '离职结算' => [
            'base' => '固定门店时取档案底薪；当月按「固定薪资折算」覆盖',
            'performance' => '同上',
            'hourly' => '按实际课时',
            'hourlyIncentive' => '享私教课时费激励',
            'commission' => '普通阶梯',
            'baseReward' => '无',
            'storeCommission' => '无',
        ],
    ];

    /**
     * 归一化身份标签：去掉 `{门店}:` 前缀写法（主档实测存在 `东部店:顾问`），
     * 并容忍全角冒号与首尾空白。归一化后的值才是**存储层枚举**。
     */
    public static function normalizeRole(?string $role): string
    {
        $role = trim((string) $role);
        if ($role === '') {
            return '';
        }
        $role = str_replace('：', ':', $role);
        if (str_contains($role, ':')) {
            $parts = explode(':', $role);
            $role = trim((string) end($parts));
        }

        return $role;
    }

    public static function isValidRole(?string $role): bool
    {
        return in_array(self::normalizeRole($role), self::ROLES, true);
    }

    public static function isValidVenue(?string $venue): bool
    {
        return in_array(trim((string) $venue), self::VENUES, true);
    }

    /** 展示层标签（引擎 `岗位显示()`，S1:46-50）—— **只用于展示，禁止回写档案** */
    public static function displayRole(?string $role, ?string $home, ?string $store): string
    {
        $role = self::normalizeRole($role);
        $home = trim((string) $home);
        $store = trim((string) $store);

        if ($role === '馆主') {
            return '管理层';
        }
        // 引擎原样：`全职老师` 与 `顾问` 跨店时都显示成「{所属门店}全职老师」。
        // 这是历史展示口径（顾问跨店极少见），照抄以免与工资表对不上。
        if (in_array($role, ['全职老师', '顾问'], true) && $home !== '' && $home !== $store) {
            return $home.'全职老师';
        }
        if ($role === '全职老师') {
            return $store.'全职老师';
        }

        return $role;
    }

    /** 底薪是否参与计算：非兼职老师才吃档案底薪（S1:169-170） */
    public static function allowsBaseSalary(?string $role): bool
    {
        return self::normalizeRole($role) !== '兼职老师';
    }

    /** 绩效是否参与计算：同上（S1:171） */
    public static function allowsPerformance(?string $role): bool
    {
        return self::normalizeRole($role) !== '兼职老师';
    }

    /** 底薪奖励：仅全职老师（S1:173） */
    public static function allowsBaseReward(?string $role): bool
    {
        return self::normalizeRole($role) === '全职老师';
    }

    /** 私教课时费激励：专职老师不享（S1:177） */
    public static function allowsHourlyIncentive(?string $role): bool
    {
        return self::normalizeRole($role) !== '专职老师';
    }

    /** 销售提成模式：`fixed7`（专职老师固定 7%）或 `ladder`（普通阶梯） */
    public static function commissionMode(?string $role): string
    {
        return self::normalizeRole($role) === '专职老师' ? 'fixed7' : 'ladder';
    }

    /** 店长门店提成率（S1:181-182；其他标签为 0，例外走档案覆盖值） */
    public static function storeCommissionRate(?string $role): string
    {
        return self::normalizeRole($role) === '店长' ? '0.02' : '0';
    }

    /** 底薪奖励档位金额（S1:39-45） */
    public static function baseRewardFor(int $accumulatedValidHours): int
    {
        foreach (self::BASE_REWARD_TIERS as $tier) {
            if ($accumulatedValidHours >= $tier['hours']) {
                return $tier['amount'];
            }
        }

        return 0;
    }

    /**
     * 私教课时费「加价/节」（S1:29-37）。
     *
     * 门槛 = 档位门槛 × 活动月倍数；**实际业绩不乘倍数**（S1:158）。
     */
    public static function hourlyAddOn(int $accumulatedPerformanceCents, float $thresholdMultiplier): int
    {
        foreach (self::HOURLY_INCENTIVE_TIERS as $tier) {
            $threshold = (int) round($tier['threshold'] * 100 * $thresholdMultiplier);
            if ($accumulatedPerformanceCents >= $threshold) {
                return $tier['addOn'];
            }
        }

        return 0;
    }

    /** 普通销售提成阶梯（S1:24-28）：返回 [提成率(字符串), 提成率分母], 业绩为 0 时比率为 0 */
    public static function commissionRateFor(int $performanceCents): string
    {
        if ($performanceCents <= 0) {
            return '0';
        }
        foreach (self::COMMISSION_TIERS as $tier) {
            if ($performanceCents >= $tier['threshold'] * 100) {
                return (string) $tier['rate'];
            }
        }

        return '0';
    }

    /** 枚举 + 规则说明（`GET /payroll/roles` 的唯一数据源） */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::ROLES as $role) {
            $out[] = [
                'value' => $role,
                'label' => $role === '馆主' ? '馆主（显示为「管理层」）' : $role,
                'salaryRules' => self::RULES[$role],
            ];
        }

        return $out;
    }

    /** 允许的活动月类型与倍数（引擎硬闸门：类型与倍数必须匹配且已确认） */
    public static function activityTypes(): array
    {
        return self::ACTIVITY_MULTIPLIERS;
    }
}
