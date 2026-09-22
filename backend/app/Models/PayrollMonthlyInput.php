<?php

namespace App\Models;

use App\Support\PayrollMoney;
use App\Support\PayrollRoles;
use Illuminate\Database\Eloquent\Model;

/**
 * 薪酬月度输入：考勤 / 社保 / 个税 / 调整项 / 活动月规则 / 门店销售额确认。
 *
 * `social_security_mode` 三态是本模型最重要的语义，见迁移
 * `2026_09_22_000101_create_payroll_monthly_inputs_table.php` 的类注释。
 */
class PayrollMonthlyInput extends Model
{
    protected $guarded = [];

    public const SOCIAL_SET = 'set';

    public const SOCIAL_INHERIT = 'inherit';

    public const SOCIAL_OFF = 'off';

    public const SOCIAL_MODES = [self::SOCIAL_INHERIT, self::SOCIAL_SET, self::SOCIAL_OFF];

    protected $casts = [
        'attendance_days' => 'decimal:2',
        'personal_leave_hours' => 'decimal:2',
        'sick_leave_hours' => 'decimal:2',
        'social_security' => 'decimal:2',
        'tax' => 'decimal:2',
        'subsidy' => 'decimal:2',
        'previous_adjustment' => 'decimal:2',
        'other_deduction' => 'decimal:2',
        'fixed_salary_override' => 'decimal:2',
        'base_salary_zeroed' => 'boolean',
        'store_commission_addon' => 'decimal:2',
        'threshold_multiplier' => 'decimal:2',
        'confirmed_store_sales' => 'decimal:2',
    ];

    public function profile()
    {
        return $this->belongsTo(PayrollProfile::class, 'payroll_profile_id');
    }

    /** 社保模式（缺失按 `inherit`：没有行 = 本月未操作） */
    public function socialMode(): string
    {
        $mode = (string) $this->social_security_mode;

        return in_array($mode, self::SOCIAL_MODES, true) ? $mode : self::SOCIAL_INHERIT;
    }

    /** 本月显式设定了社保金额（含显式 0） */
    public function socialIsExplicit(): bool
    {
        return $this->socialMode() === self::SOCIAL_SET;
    }

    /** 本月显式停缴 */
    public function socialIsOff(): bool
    {
        return $this->socialMode() === self::SOCIAL_OFF;
    }

    /**
     * 请假扣款（分）。口径 S1:120-124：
     * `请假天数 = (事假 + 病假) / 8`；`扣款 = 底薪 / 应出勤天数 × 请假天数`。
     *
     * 只扣事假 + 病假；迟到、早退、年假、学习假不进工资扣款（S2:40）。
     * 应出勤天数为 0 或缺失而请假小时 > 0 时**无法计算** —— 返回 null 而不是 0，
     * 由调用方记 `warnings`，不得静默按 0 放过（S1:123 的高等级异常）。
     */
    public function leaveDeductionCents(int $baseSalaryCents): ?int
    {
        $leaveHours = PayrollMoney::cents($this->personal_leave_hours) + PayrollMoney::cents($this->sick_leave_hours);
        if ($leaveHours <= 0) {
            return 0;
        }
        $days = PayrollMoney::cents($this->attendance_days);
        if ($days <= 0) {
            return null;
        }
        // 请假天数 = 小时 / 8。按「千分之一天」做定点，避免先转分再除 8 丢精度
        $leaveDaysMilli = intdiv($leaveHours * 1000, 8 * 100);
        // 扣款 = (底薪 / 应出勤天数) × 请假天数。逐笔 half-up 到分（引擎口径）
        $perDayCents = PayrollMoney::mulRate($baseSalaryCents, 100, $days);

        return PayrollMoney::mulRate($perDayCents, $leaveDaysMilli, 1000);
    }

    /** 活动月门槛倍数（未确认时按 1，并另行阻断） */
    public function thresholdMultiplier(): float
    {
        return $this->threshold_multiplier === null ? 1.0 : (float) $this->threshold_multiplier;
    }

    /** 活动规则是否已确认（引擎硬闸门：未确认直接抛错，S1:109-110） */
    public function activityConfirmed(): bool
    {
        return (string) $this->activity_confirm_status === '已确认'
            && isset(PayrollRoles::ACTIVITY_MULTIPLIERS[(string) $this->activity_type]);
    }
}
