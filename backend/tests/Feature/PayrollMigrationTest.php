<?php

namespace Tests\Feature;

use App\Support\PayrollMoney;
use App\Support\PayrollRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 迁移幂等与表结构（`migrate` 重复可重跑）。
 *
 * `RefreshDatabase` 每个用例都会重建库；本用例额外显式跑一次 `migrate`，
 * 覆盖「同一进程内重复执行迁移」的场景（升级脚本会先 migrate 再跑测试）。
 */
class PayrollMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_三张表都存在(): void
    {
        foreach ([
            'payroll_profiles',
            'payroll_monthly_inputs',
            'payroll_performances',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "缺少表 {$table}");
        }
    }

    public function test_档案表字段齐全(): void
    {
        $columns = [
            // 一人一行 + 身份标签 + 所属门店
            'user_id', 'external_id', 'name', 'venue', 'role',
            // 基本底薪 / 绩效
            'base_salary', 'performance',
            // 60/45 分钟课时费（45 是独立字段，不是派生值）
            'fee_private60', 'fee_private45',
            // 小班 / 团课 / 企业课课时费
            'fee_small', 'fee_group', 'fee_enterprise',
            // 双底薪例外 + 提成率覆盖
            'dual_base_salary', 'store_commission_rate', 'commission_fixed_rate', 'aliases',
            // 人员状态 / 重点提醒 / 账户确认状态 / 备注
            'status', 'alert', 'account_status', 'note',
        ];
        foreach ($columns as $c) {
            $this->assertTrue(Schema::hasColumn('payroll_profiles', $c), "payroll_profiles 缺少列 {$c}");
        }
    }

    public function test_月度输入含社保三态与可空的社保金额(): void
    {
        foreach ([
            'attendance_days', 'personal_leave_hours', 'sick_leave_hours',
            'social_security', 'social_security_mode', 'tax',
            'subsidy', 'previous_adjustment', 'other_deduction',
            'fixed_salary_override', 'base_salary_zeroed', 'store_commission_addon',
            'activity_type', 'threshold_multiplier', 'activity_confirm_status',
            'confirmed_store_sales', 'store_sales_confirm_status',
        ] as $c) {
            $this->assertTrue(Schema::hasColumn('payroll_monthly_inputs', $c), "payroll_monthly_inputs 缺少列 {$c}");
        }

        // `social_security` 必须可空：null（未操作）与 0（显式不缴）语义不同
        $cols = collect(\Illuminate\Support\Facades\DB::select('PRAGMA table_info(payroll_monthly_inputs)'))
            ->keyBy('name');
        $this->assertSame(0, (int) $cols['social_security']->notnull, 'social_security 必须可空（null≠0）');
    }

    public function test_业绩表存两套金额(): void
    {
        foreach ([
            'venue', 'month', 'transaction_key', 'occurred_on', 'member_name',
            'payment_type', 'payment_method', 'transaction_amount', 'is_activity_card',
            'allocation_type', 'payroll_profile_id', 'user_id', 'source_name', 'resolved_name',
            'raw_amount', 'commission_amount', 'store_sales_amount',
            'source_file_name', 'source_sha256', 'source_sheet', 'source_row',
        ] as $c) {
            $this->assertTrue(Schema::hasColumn('payroll_performances', $c), "payroll_performances 缺少列 {$c}");
        }
    }

    /** 重复执行 migrate 不报错（幂等可重跑） */
    public function test_重复执行迁移不报错(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertTrue(Schema::hasTable('payroll_profiles'));
    }

    /** 金额定点运算：全链路不出现浮点累加 */
    public function test_金额定点运算(): void
    {
        // 解析
        $this->assertSame(8985750, PayrollMoney::cents('89857.50'));
        $this->assertSame(0, PayrollMoney::cents(null));
        $this->assertSame(0, PayrollMoney::cents(''));
        $this->assertSame(-100, PayrollMoney::cents('-1.00'));

        // 半进位（不是 banker's rounding）
        $this->assertSame(629003, PayrollMoney::mulFactor(8985750, '0.07'), '89857.50×7% 应为 6290.03');
        $this->assertSame(448711, PayrollMoney::mulFactor(6410150, '0.07'), '64101.50×7% 应为 4487.11');
        // 4,487.105 → half-up 到 4,487.11（banker's 会到 4,487.10）
        $this->assertSame('4487.11', PayrollMoney::fmt(PayrollMoney::mulFactor(6410150, '0.07')));
        // 半进位 vs banker's 的分水岭：`x.xx5` 一律**向上**到分。
        // 0.125 → 0.13（banker's 会给 0.12，因为 2 是偶数）
        $this->assertSame('0.13', PayrollMoney::fmt(PayrollMoney::fromString('0.125')));
        // 0.135 → 0.14（banker's 会给 0.14 也一致，但 0.145 → 0.15 而 banker's 给 0.14）
        $this->assertSame('0.15', PayrollMoney::fmt(PayrollMoney::fromString('0.145')));
        $this->assertNotSame('0.14', PayrollMoney::fmt(PayrollMoney::fromString('0.145')));

        // 格式化
        $this->assertSame('7283.34', PayrollMoney::fmt(728334));
        $this->assertSame('-7283.34', PayrollMoney::fmt(-728334));
        $this->assertSame('0.00', PayrollMoney::fmt(0));
    }

    /** 枚举常量与 t2 规格一致 */
    public function test_枚举与规格一致(): void
    {
        $this->assertSame([
            '馆主', '店长', '全职老师', '专职老师', '兼职老师', '顾问',
            '新媒体', '保洁', '固定发放', '已离职', '离职结算',
        ], PayrollRoles::ROLES);
        $this->assertSame(['东部店', '绿地店'], PayrollRoles::VENUES);
        $this->assertSame(['蒙澍南', '谭婷婷', '张卫玉'], PayrollRoles::DUAL_BASE_SALARY_WHITELIST);
        $this->assertSame(['普通月' => 1.0, '品牌月' => 2.0, '周年庆' => 2.5], PayrollRoles::ACTIVITY_MULTIPLIERS);

        // 两个 0.75 是**不同**的常量（基数不同，不得合并）
        $this->assertSame('0.75', PayrollRoles::FEE_45_FALLBACK_FACTOR);
        $this->assertSame('0.75', PayrollRoles::INCENTIVE_45_FACTOR);
    }
}
