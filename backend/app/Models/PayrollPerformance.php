<?php

namespace App\Models;

use App\Support\PayrollMoney;
use Illuminate\Database\Eloquent\Model;

/**
 * 业绩导入结果：**一行 = 一条归属**（不是一条交易）。
 *
 * 一条交易可同时归属多个销售员（样表实测 3 行），所以拆成多行、用 `source_row` 回溯。
 * 两套金额同时落库：`raw_amount`（原始，含 299 活动卡）用于核对，
 * `commission_amount` / `store_sales_amount`（剔除 299）用于提成。
 * 详见 `2026_09_22_000102_create_payroll_performances_table.php`。
 */
class PayrollPerformance extends Model
{
    protected $guarded = [];

    public const ALLOC_PERSONAL = 'personal';

    public const ALLOC_VENUE = 'venue';

    protected $casts = [
        'occurred_on' => 'date',
        'transaction_amount' => 'decimal:2',
        'raw_amount' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'store_sales_amount' => 'decimal:2',
        'is_activity_card' => 'boolean',
    ];

    public function profile()
    {
        return $this->belongsTo(PayrollProfile::class, 'payroll_profile_id');
    }

    public function rawCents(): int
    {
        return PayrollMoney::cents($this->raw_amount);
    }

    public function commissionCents(): int
    {
        return PayrollMoney::cents($this->commission_amount);
    }

    public function storeSalesCents(): int
    {
        return PayrollMoney::cents($this->store_sales_amount);
    }
}
