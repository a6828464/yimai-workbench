<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use App\Models\Approval;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class YimaiSeeder extends Seeder
{
    public function run(): void
    {
        $demoPassword = (string) env('DEMO_PASSWORD', '');
        $accounts = [
            ['username' => 'owner', 'name' => '演示超管', 'role' => 'R_SUPER', 'venue' => null, 'venues' => ['绿地店', '东部店'], 'email' => 'owner@example.invalid'],
            ['username' => 'manager-green', 'name' => '绿地店长', 'role' => 'R_MANAGER', 'venue' => '绿地店', 'venues' => ['绿地店'], 'email' => 'manager-green@example.invalid'],
            ['username' => 'manager-east', 'name' => '东部店长', 'role' => 'R_MANAGER', 'venue' => '东部店', 'venues' => ['东部店'], 'email' => 'manager-east@example.invalid'],
            ['username' => 'teacher', 'name' => '演示老师', 'role' => 'R_TEACHER', 'venue' => '绿地店', 'venues' => ['绿地店'], 'email' => 'teacher@example.invalid'],
            ['username' => 'media', 'name' => '演示新媒体', 'role' => 'R_MEDIA', 'venue' => null, 'venues' => ['绿地店', '东部店'], 'email' => 'media@example.invalid'],
        ];
        $this->command->info('seed: users');
        if ($demoPassword !== '') foreach ($accounts as $a) {
            User::create($a + ['email_verified_at' => now(), 'password' => Hash::make($demoPassword)]);
        }

        $this->command->info('seed: customers');
        foreach ([
            ['王雅琴', '13805742073', '绿地店', '大众点评', '精品白领年卡（3次/周）', 18, '2026-11-08', '2026-08-24', 'P0', '婷婷', 6, 5, 4, 120],
            ['李梦', '13805745581', '绿地店', '朋友介绍', 'VIP私教200节', 66, '2027-01-06', '2026-07-02', 'P1', '冰璐', 8, 7, 7, 150],
            ['张璐', '13805743390', '东部店', '美团', '全能小班一年卡', 42, '2026-12-20', '2026-08-25', 'P4', '芷晴', 5, 6, 6, 60],
            ['陈晓芸', '13805748826', '绿地店', '小红书', '私享定制小班', 1, '2026-09-15', '2026-08-10', 'P0', '娟子', 4, 3, 2, 80],
            ['刘思颖', '13805744417', '东部店', '自然到店', '—', null, null, '2026-08-22', 'P5', '苏米', 2, 1, 0, 0],
            ['赵一诺', '13805749052', '绿地店', '抖音', '—', null, null, '2026-08-21', 'P5', '婷婷', 3, 2, 2, 0],
            ['孙美琪', '13805746634', '东部店', '美团', '精品团课季卡', 6, '2026-10-30', '2026-06-18', 'P2', '张青', 9, 6, 3, 45],
            ['周雨彤', '13805741278', '绿地店', '朋友介绍', 'VIP私教50节', 12, '2026-09-28', '2026-07-20', 'P3', '冰璐', 3, 2, 0, 55],
            ['吴佳宁', '13805747745', '东部店', '大众点评', '全能小班年卡', 88, '2027-03-01', '2026-08-19', 'P4', '芷晴', 6, 6, 5, 110],
            ['郑好', '13805743509', '绿地店', '美团', '—', null, null, '2026-08-20', 'P5', '未分配', 0, 0, 0, 0],
            ['冯悦', '13805746821', '绿地店', '小红书', '精品团课年卡', 30, '2026-12-11', '2026-08-23', 'P0', '娟子', 7, 7, 6, 95],
            ['许静姝', '13805749913', '东部店', '朋友介绍', 'VIP私教月卡', 0, '2026-08-31', '2026-08-15', 'P0', '苏米', 8, 4, 4, 35],
        ] as [$name, $phone, $venue, $source, $card, $remain, $expire, $visit, $layer, $owner, $m1, $m2, $m3, $total]) {
            Customer::create([
                'name' => $name, 'phone' => $phone, 'phone_tail' => substr($phone, -4),
                'venue' => $venue, 'source' => $source, 'main_card' => $card,
                'remain_times' => $remain, 'expire_date' => $expire, 'last_visit' => $visit,
                'layer' => $layer, 'status' => '跟进中', 'owner' => $owner,
                'next_action' => '按清单执行', 'next_action_time' => '2026-08-28',
                'attend_m1' => $m1, 'attend_m2' => $m2, 'attend_m3' => $m3, 'total_purchased' => $total,
            ]);
        }

        $this->command->info('seed: leads');
        foreach ([
            ['2026-08-24', '康女士', '13805745021', '体验大器械', '抖音', '绿地店', '', '新留资', '', '', '', '', '', null, null, '106541651406498', '抖音团购券未核销'],
            ['2026-08-24', 'summer', '13705744022', '体式提升', '转介绍', '绿地店', '张芷晴', '已约体验', 'B', '2026-08-26 12:05', '内观流', '张芷晴', '', null, 99, '101654267712226', '朋友同报'],
            ['2026-08-25', '章月', '13567542747', '产后修复', '美团', '东部店', '苏米', '已联系', '', '', '', '', '', null, null, '', '产后8个月需评估'],
            ['2026-08-18', '王燕', '15858401132', '小班系统练习', '抖音周年庆直播', '东部店', '黄敏', '已成交', 'B', '2026-08-19 18:30', '核心床小班', '黄敏', '全能小班36节半年卡', 4091, 299, '106541659980001', '直播当场下单'],
        ] as $l) {
            Lead::create([
                'lead_date' => $l[0], 'name' => $l[1], 'phone' => $l[2], 'demand' => $l[3],
                'source' => $l[4], 'venue' => $l[5], 'service_teacher' => $l[6], 'status' => $l[7],
                'grade' => $l[8], 'trial_time' => $l[9], 'trial_topic' => $l[10], 'trial_teacher' => $l[11],
                'deal_card' => $l[12], 'deal_amount' => $l[13], 'redeem_amount' => $l[14],
                'voucher_code' => $l[15], 'remark' => $l[16], 'created_by' => '阿玉',
            ]);
        }

        $this->command->info('seed: tasks');
        Task::create(['title' => '新客首次响应', 'customer_name' => '郑好', 'venue' => '绿地店', 'owner' => '未分配', 'priority' => '高', 'deadline' => '2026-08-26 18:00', 'status' => '已逾期', 'standard' => '留资后24小时内完成首联并记录意向']);
        Task::create(['title' => '体验后跟进', 'customer_name' => '刘思颖', 'venue' => '东部店', 'owner' => '苏米', 'priority' => '高', 'deadline' => '2026-08-26 20:00', 'status' => '进行中', 'standard' => '体验后24小时内有效跟进并生成下次动作']);

        Approval::create(['customer_name' => '刘思颖', 'applicant' => '苏米', 'card_name' => '全能小班年卡', 'standard_price' => 8800, 'request_price' => 7980, 'reason' => '体验当天成交，竞品对比价差敏感', 'status' => '待店长初审', 'apply_time' => '2026-08-26 10:24']);
        Approval::create(['customer_name' => '周雨彤', 'applicant' => '冰璐', 'card_name' => 'VIP私教50节', 'standard_price' => 22500, 'request_price' => 19800, 'reason' => '老客复购+过期余额折抵权益', 'status' => '待老板终审', 'apply_time' => '2026-08-25 16:40']);

        AppSetting::create(['rules' => ['renewalThreshold' => 10, 'vipThreshold' => 100, 'declineMode' => 'strict']]);
    }
}
