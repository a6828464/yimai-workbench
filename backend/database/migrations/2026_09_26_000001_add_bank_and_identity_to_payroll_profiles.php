<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 人员档案扩展：收款账户 + 联系方式（用户决策 2026-09-26）
 *
 * ## 背景
 *
 * 用户要求「人员档案」显示身份信息与银行卡信息，并明确拍板**完整卡号入库**
 * （打破此前「PII 不入库」的默认政策 —— 该决策由用户显式做出，本迁移是其执行）。
 * 此前这些信息只存在于仓库外的主档 xlsx，发薪前人工核对；现在进库后由
 * 薪酬页「人员档案」直接展示。
 *
 * ## 安全边界（虽然入库，但收紧可见面）
 *
 * 1. 所有新列**只经超管端点下发**：`GET/PUT /payroll/profiles` 全部 `requireSuper`，
 *    与底薪/课时费同一权限面（本就不对非超管开放）。
 * 2. 前端展示默认**掩码**（卡号只显示后 4 位），点「显示」才露出完整卡号 ——
 *    防肩窥/截屏误发；编辑仍提交完整值。
 * 3. 仓库仍**不提交**主档 xlsx 本体；这些列由 seeder 从仓库外文件灌入。
 *
 * ## 字段说明
 *
 * - `bank_account_name` 收款户名（与真实姓名可能不同，如代发亲属卡）
 * - `bank_card_no` 银行卡号（完整入库；界面掩码显示）
 * - `bank_name` 开户行/网点（如「宁波鄞州农村商业银行股份有限公司江北支行」）
 * - `bank_cnaps` 联行号（跨行转账用，12 位）
 * - `transfer_type` 转账类型（行内/行外）
 * - `phone` 手机号（主档缺 41 人，可空）
 * - `id_card_no` 身份证号（主档缺 40 人，可空；仅存储，界面同掩码显示）
 * - `wechat_work` 企业微信账号（主档缺 26 人，可空）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_profiles', function (Blueprint $table) {
            $table->string('bank_account_name', 60)->default('')->after('note');
            $table->string('bank_card_no', 32)->default('')->after('bank_account_name');
            $table->string('bank_name', 120)->default('')->after('bank_card_no');
            $table->string('bank_cnaps', 16)->default('')->after('bank_name');
            $table->string('transfer_type', 16)->default('')->after('bank_cnaps');
            $table->string('phone', 20)->default('')->after('transfer_type');
            $table->string('id_card_no', 32)->default('')->after('phone');
            $table->string('wechat_work', 60)->default('')->after('id_card_no');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'bank_account_name', 'bank_card_no', 'bank_name', 'bank_cnaps',
                'transfer_type', 'phone', 'id_card_no', 'wechat_work',
            ]);
        });
    }
};
