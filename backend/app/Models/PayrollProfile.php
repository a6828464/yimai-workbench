<?php

namespace App\Models;

use App\Support\PayrollMoney;
use App\Support\PayrollRoles;
use Illuminate\Database\Eloquent\Model;

/**
 * 薪酬档案：一人一行（身份标签 + 底薪/绩效 + 各课型课时费 + 双底薪例外 + 状态）。
 *
 * 说明见 `2026_09_22_000100_create_payroll_profiles_table.php` 与
 * `docs/薪酬/薪酬计算栏目规格.md` §2、§5。
 */
class PayrollProfile extends Model
{
    protected $guarded = [];

    protected $casts = [
        'base_salary' => 'decimal:2',
        'performance' => 'decimal:2',
        'fee_private60' => 'decimal:2',
        'fee_private45' => 'decimal:2',
        'fee_small' => 'decimal:2',
        'fee_group' => 'decimal:2',
        'fee_enterprise' => 'decimal:2',
        'store_commission_rate' => 'decimal:4',
        'commission_fixed_rate' => 'decimal:4',
        'dual_base_salary' => 'boolean',
        // 待完善：关键字段（身份标签/单价）未经确认，禁止参与工资计算（见建列迁移说明）
        'pending_review' => 'boolean',
        // 姓名别名：JSON 数组（如 ["苏米"]）。本名恒可解析，无需登记
        'aliases' => 'array',
    ];

    /**
     * 该档案是否可用于工资计算。
     *
     * `pending_review` 为真时**整行不得参与计算** —— 「字段留空」在现有实现里
     * 不等于「不参与计算」：`role` 列有 `default('全职老师')`，而
     * `allowsBaseReward('全职老师')` 会按实际课时发 200~1000 元底薪奖励
     * （**不看档案金额**），猜错身份标签就是把钱算错人。
     */
    public function isCalculable(): bool
    {
        return ! (bool) $this->pending_review;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** 底薪（分） */
    public function baseSalaryCents(): int
    {
        return PayrollMoney::cents($this->base_salary);
    }

    public function performanceCents(): int
    {
        return PayrollMoney::cents($this->performance);
    }

    /**
     * 45 分钟课时费（分）。为 0 时按 60 分钟 × 0.75 自动折算（S1:175）。
     *
     * 折算**不写回档案**：档案里保持 0 才能表达「未单独设置」，一写回就再也分不清
     * 「人工填了 0」和「没填」。折算只在计算时发生。
     */
    public function private45FeeCents(): int
    {
        $explicit = PayrollMoney::cents($this->fee_private45);
        if ($explicit > 0) {
            return $explicit;
        }

        return PayrollMoney::mulFactor(PayrollMoney::cents($this->fee_private60), PayrollRoles::FEE_45_FALLBACK_FACTOR);
    }

    /** 是否按 60 分钟折算出的 45 分钟单价（供响应里暴露，让用户看得出哪档是推算的） */
    public function private45IsDerived(): bool
    {
        return PayrollMoney::cents($this->fee_private45) <= 0;
    }

    /**
     * 有效销售提成率（字符串小数）。
     *
     * 优先取档案覆盖值（如邓淼月的固定 7%），否则按身份标签：
     * 专职老师固定 7%、其余走普通阶梯 —— 两条路都收口在 `PayrollRoles`。
     */
    public function commissionRateFor(int $performanceCents): string
    {
        $override = $this->commission_fixed_rate;
        if ($override !== null && (float) $override > 0) {
            return (string) $override;
        }
        if (PayrollRoles::commissionMode($this->role) === 'fixed7') {
            return '0.07';
        }

        return PayrollRoles::commissionRateFor($performanceCents);
    }

    /**
     * 有效门店提成率（字符串小数）。
     *
     * 引擎里馆主蒙澍南的 5% 是**姓名硬编码**（S1:182），档案化后改为档案覆盖值 ——
     * 这样新增同类人员只需改数据，不必再改代码。
     */
    public function storeCommissionRateValue(): string
    {
        $override = $this->store_commission_rate;
        if ($override !== null && (float) $override > 0) {
            return (string) $override;
        }

        return PayrollRoles::storeCommissionRate($this->role);
    }

    /** 展示层岗位（馆主→管理层、跨店→{所属门店}全职老师） */
    public function displayRole(?string $store = null): string
    {
        return PayrollRoles::displayRole($this->role, $this->venue, $store ?? $this->venue);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'externalId' => $this->external_id,
            'name' => $this->name,
            'venue' => $this->venue,
            'role' => $this->role,
            'roleLabel' => PayrollRoles::displayRole($this->role, $this->venue, $this->venue),
            'baseSalary' => PayrollMoney::toFloat($this->baseSalaryCents()),
            'performance' => PayrollMoney::toFloat($this->performanceCents()),
            'feePrivate60' => PayrollMoney::toFloat(PayrollMoney::cents($this->fee_private60)),
            'feePrivate45' => PayrollMoney::toFloat(PayrollMoney::cents($this->fee_private45)),
            // 档案里没单独设 45 分钟价时，把折算结果一并给出（前端可显示「折算 ¥120」）
            'feePrivate45Effective' => PayrollMoney::toFloat($this->private45FeeCents()),
            'feePrivate45Derived' => $this->private45IsDerived(),
            'feeSmall' => PayrollMoney::toFloat(PayrollMoney::cents($this->fee_small)),
            'feeGroup' => PayrollMoney::toFloat(PayrollMoney::cents($this->fee_group)),
            'feeEnterprise' => PayrollMoney::toFloat(PayrollMoney::cents($this->fee_enterprise)),
            'dualBaseSalary' => (bool) $this->dual_base_salary,
            'storeCommissionRate' => (float) $this->storeCommissionRateValue(),
            'commissionFixedRate' => $this->commission_fixed_rate === null ? null : (float) $this->commission_fixed_rate,
            'status' => $this->status,
            'alert' => (string) $this->alert,
            'accountStatus' => (string) $this->account_status,
            // 待完善：前端要据此打「待完善」标记并提示「不参与计算」
            'pendingReview' => (bool) $this->pending_review,
            'note' => (string) $this->note,
            'aliases' => array_values((array) ($this->aliases ?? [])),
        ];
    }
}
