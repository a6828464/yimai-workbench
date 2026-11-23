<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\KyBooking;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `/today/todo` 六组卡片信息增强（v3.19.0）。
 *
 * 这些字段解决的是同一类真机反馈：「待办里只有名字和时间，要判断该不该催、
 * 卡还剩几次、多久没来，得一路跳进会员详情」。
 *
 * 本文件的第二条硬要求是**纯新增**：每次断言新字段时，同组必须同时断言
 * key / done / doneBy / doneRemark / lists / urgent 这些既有键的语义没被顺手改掉。
 * 只测新字段的话，谁把 `done` 从 bool 改成 null 都发现不了 —— 而前端是拿它消隐卡片的。
 */
class TodayTodoEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_bookings_carry_attend_main_card_and_hint(): void
    {
        Sanctum::actingAs($this->super());

        Customer::create($this->customerAttributes('带卡会员', '13800000001', [
            'main_card' => '私教小班', 'remain_times' => 3,
            'attend_m1' => 5, 'attend_m2' => 4, 'attend_m3' => 3,
        ]));
        $this->booking('带卡会员', '13800000001', '09:00', 'booked', ['course_type' => '2']);
        // 没有会员档案的预约：attend 必须是全 0 且 attendKnown=false，
        // 否则前端会把「查不到人」渲染成「近三月没来」—— 两种结论的动作完全相反
        $this->booking('陌生体验客', '', '10:00', 'booked', ['course_type' => '1'], true);

        $data = $this->getJson('/api/today/todo')->assertOk()->json('data');
        $items = collect($data['bookings']['items'])->keyBy('memberName');

        $known = $items['带卡会员'];
        $this->assertSame(['m1' => 5, 'm2' => 4, 'm3' => 3], $known['attend'], '出勤三值与 customers.attend_m* 同源同序');
        $this->assertTrue($known['attendKnown']);
        $this->assertSame('私教小班', $known['mainCard']);
        $this->assertSame(3, $known['remainTimes']);
        $this->assertSame('私教小班 · 剩 3 节', $known['cardHint']);

        $unknown = $items['陌生体验客'];
        $this->assertSame(['m1' => 0, 'm2' => 0, 'm3' => 0], $unknown['attend']);
        $this->assertFalse($unknown['attendKnown']);
        $this->assertSame('', $unknown['mainCard']);
        $this->assertNull($unknown['remainTimes']);
        $this->assertSame('', $unknown['cardHint'], '无卡时提示必须是空串，不能是「—」');

        // 既有键不动
        $this->assertSame('booking:'.$known['id'], $known['key']);
        $this->assertFalse($known['done']);
        $this->assertContains('待续课', $known['lists']);
        $this->assertSame(1, $data['bookings']['trialCount'], 'isTrial 统计口径不变');
    }

    public function test_renewals_pick_the_nearest_or_lowest_balance_card(): void
    {
        Sanctum::actingAs($this->super());

        $c = Customer::create($this->customerAttributes('待续会员', '13800000002', [
            'main_card' => '团课10次', 'remain_times' => 10,
            'attend_m3' => 4,
            'expire_date' => now()->addDays(7)->toDateString(),
            // cards_list 是待续费判定的真源：这里给两张在用卡，
            // 一张 7 天后到期（只给 deadline，用来验证 daysLeft 缺失时的归一兜底），
            // 一张 60 天后到期（带 daysLeft）
            'cards_list' => [
                ['id' => 'r1', 'cardNo' => 'R001', 'title' => '私教小班', 'unit' => '节', 'residue' => 3,
                    'bound' => 30, 'deadline' => now()->addDays(7)->toDateString(), 'state' => 'current',
                    'unactivated' => false, 'type' => '1'],
                ['id' => 'r2', 'cardNo' => 'R002', 'title' => '团课10次', 'unit' => '节', 'residue' => 10,
                    'bound' => 10, 'deadline' => now()->addDays(60)->toDateString(), 'daysLeft' => 60,
                    'state' => 'current', 'unactivated' => false, 'type' => '1'],
            ],
        ]));
        $this->booking('待续会员', '13800000002', '09:30', 'booked', ['course_type' => '2']);

        $data = $this->getJson('/api/today/todo')->assertOk()->json('data');
        $renewal = collect($data['renewals'])->firstWhere('name', '待续会员');
        $this->assertNotNull($renewal, '该会员必须进待续费组，否则本用例空转');

        $this->assertSame('私教小班', $renewal['cardName'], '按 daysUntil 升序取第一张，不是主卡');
        $this->assertSame('私教小班 · 剩 3 节 · 7 天后到期', $renewal['cardHint']);
        $this->assertSame('私教小班剩 3 节，7 天后到期', $renewal['whyText']);

        // 既有键：mainCard / remainTimes 仍然是 customers 汇总列（语义未改），urgent 仍是 bool
        $this->assertSame('团课10次', $renewal['mainCard']);
        $this->assertSame(10, $renewal['remainTimes']);
        $this->assertIsBool($renewal['urgent']);
        $this->assertSame('renewal:'.$c->id, $renewal['key']);
        $this->assertFalse($renewal['done']);
        $this->assertContains('待续课', $renewal['lists']);
    }

    public function test_renewals_fall_back_to_main_card_without_cards_list(): void
    {
        Sanctum::actingAs($this->super());

        // 老数据：没有 cards_list，只有汇总列。回退口径必须在契约里（无卡时回退 customers.main_card）
        Customer::create($this->customerAttributes('老数据会员', '13800000003', [
            'main_card' => '私教卡', 'remain_times' => 5, 'attend_m3' => 4,
            'expire_date' => now()->addDays(5)->toDateString(),
        ]));
        $this->booking('老数据会员', '13800000003', '11:00', 'booked', ['course_type' => '2']);

        $data = $this->getJson('/api/today/todo')->assertOk()->json('data');
        $renewal = collect($data['renewals'])->firstWhere('name', '老数据会员');
        $this->assertNotNull($renewal);
        $this->assertSame('私教卡', $renewal['cardName']);
        $this->assertSame('私教卡 · 剩 5 节 · 5 天后到期', $renewal['cardHint']);
        $this->assertSame('私教卡剩 5 节，5 天后到期', $renewal['whyText']);
    }

    /**
     * 期限卡只说到期，不许把同一个数字说两遍（t4-F1，馆主真机高频可见）。
     *
     * 期限卡的 `remaining` 就是「距到期天数」，与「X 天后到期」恒等；旧实现会拼成
     * 「年卡 · 剩 7 天 · 7 天后到期」。年卡会员正是待续费主力，所以这条必须在发版前锁住。
     * 现有用例只覆盖次卡（节），这正是这个重复一直没被发现的原因 —— 所以这里专门造一张期限卡。
     */
    public function test_renewals_time_card_does_not_repeat_the_same_number(): void
    {
        Sanctum::actingAs($this->super());

        Customer::create($this->customerAttributes('年卡会员', '13800000021', [
            'main_card' => '年卡', 'remain_times' => null,
            'attend_m3' => 2,
            'expire_date' => null,   // 老数据常见形态：库列为空，到期日只在卡项里
            'cards_list' => [[
                'id' => 'y1', 'cardNo' => 'Y001', 'title' => '年卡', 'unit' => '天', 'type' => '2',
                // 期限卡：residue 就是剩余天数（与 daysLeft 恒等），真实数据即如此
                'residue' => 7, 'validDays' => 365,
                'deadline' => now()->addDays(7)->toDateString(), 'daysLeft' => 7,
                'state' => 'current', 'unactivated' => false,
            ]],
        ]));
        $this->booking('年卡会员', '13800000021', '09:00', 'booked', ['course_type' => '2']);

        $data = $this->getJson('/api/today/todo')->assertOk()->json('data');
        $renewal = collect($data['renewals'])->firstWhere('name', '年卡会员');
        $this->assertNotNull($renewal, '年卡会员必须进待续费组，否则本用例空转');

        $this->assertSame('年卡 · 7 天后到期', $renewal['cardHint'], '期限卡只说到期');
        $this->assertSame('年卡，7 天后到期', $renewal['whyText'], 'whyText 仍能读出「卡名 + 到期时间」');
        $this->assertStringNotContainsString('剩 7 天', (string) $renewal['cardHint'], '同一个数字不许说两遍');
        $this->assertStringNotContainsString('剩 7 天', (string) $renewal['whyText']);
        $this->assertStringNotContainsString('共 null', (string) $renewal['cardHint'], '取不到总量时不许出现「共 null 天」');

        // 既有键不变：cardName 仍是那张卡，mainCard/remainTimes 仍是 customers 汇总列
        $this->assertSame('年卡', $renewal['cardName']);
        $this->assertSame('年卡', $renewal['mainCard']);
        $this->assertNull($renewal['remainTimes'], '期限卡会员的 remain_times 本来就是 null，语义不动');
    }

    public function test_churn_why_text_carries_inactive_days_and_card(): void
    {
        Sanctum::actingAs($this->super());

        $lastVisit = now()->subDays(20)->toDateString();
        Customer::create($this->customerAttributes('流失会员', '13800000004', [
            'main_card' => '私教小班', 'remain_times' => 3,
            'last_visit' => $lastVisit, 'attend_m2' => 3, 'attend_m3' => 0,
            'expire_date' => now()->addDays(90)->toDateString(),
        ]));

        $data = $this->getJson('/api/today/todo')->assertOk()->json('data');
        $risk = collect($data['churnRisks'])->firstWhere('name', '流失会员');
        $this->assertNotNull($risk);

        $this->assertSame('私教小班', $risk['mainCard']);
        $this->assertSame(3, $risk['remainTimes']);
        $this->assertSame(now()->addDays(90)->toDateString(), $risk['expireDate']);
        $this->assertSame(
            '20 天没到店（上次 '.date('n-j', strtotime($lastVisit)).'）· 卡：私教小班 剩 3 节',
            $risk['whyText']
        );

        // 既有键不动（lastVisitDays 是前端「N 天未到店」标签的数据源）
        $this->assertSame(20, $risk['lastVisitDays']);
        $this->assertContains('预流失', $risk['lists']);
        $this->assertSame('churn:'.$risk['id'], $risk['key']);
        $this->assertFalse($risk['done']);
    }

    public function test_churn_why_text_omits_card_segment_without_card(): void
    {
        Sanctum::actingAs($this->super());

        Customer::create($this->customerAttributes('无卡流失', '13800000005', [
            'main_card' => '—', 'remain_times' => null,
            'last_visit' => now()->subDays(45)->toDateString(), 'attend_m2' => 2, 'attend_m3' => 0,
        ]));

        $data = $this->getJson('/api/today/todo')->assertOk()->json('data');
        $risk = collect($data['churnRisks'])->firstWhere('name', '无卡流失');
        $this->assertNotNull($risk);

        $this->assertSame('', $risk['mainCard'], 'main_card 的建表默认值「—」必须归一成空串');
        $this->assertNull($risk['remainTimes']);
        $this->assertNull($risk['expireDate']);
        $this->assertStringNotContainsString('卡：', $risk['whyText'], '无卡时整段省略，不留「卡：」空壳');
        $this->assertStringContainsString('45 天没到店', $risk['whyText']);
    }

    public function test_birthdays_carry_birthday_and_age_text(): void
    {
        Sanctum::actingAs($this->super());

        $birthday = date('Y').'-'.date('m-d');
        Customer::create($this->customerAttributes('生日会员', '13800000006', [
            'main_card' => '私教卡', 'birthday' => $birthday,
        ]));

        $data = $this->getJson('/api/today/todo')->assertOk()->json('data');
        $bd = collect($data['birthdays'])->firstWhere('name', '生日会员');
        $this->assertNotNull($bd);

        $this->assertSame(date('m-d'), $bd['birthdayText']);
        $this->assertSame(((int) date('Y') - (int) substr($birthday, 0, 4)).' 岁', $bd['ageText']);
        // 既有键：isToday / daysLater / age 语义不变
        $this->assertTrue($bd['isToday']);
        $this->assertSame(0, $bd['daysLater']);
        $this->assertSame((int) date('Y') - (int) substr($birthday, 0, 4), $bd['age']);
    }

    public function test_trials_carry_course_teacher_kind_and_lead_id(): void
    {
        Sanctum::actingAs($this->super());

        // 唯一命中：KY 体验预约的手机号只有一条进行中留资 → 必须回填它的 id
        $linked = Lead::create([
            'lead_date' => now()->toDateString(), 'name' => 'KY体验客', 'phone' => '13900000051',
            'source' => '美团', 'venue' => '绿地店', 'service_teacher' => '', 'status' => '已联系',
        ]);
        $this->booking('KY体验客', '13900000051', '10:00', 'booked', ['course_type' => '2'], true);

        // 歧义：同号两条进行中留资 → leadId 必须 null（与 todoAction 联动规则一致）
        Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '重复留资A', 'phone' => '13900000052',
            'source' => '美团', 'venue' => '绿地店', 'service_teacher' => '', 'status' => '新留资',
        ]);
        Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '重复留资B', 'phone' => '13900000052',
            'source' => '大众点评', 'venue' => '绿地店', 'service_teacher' => '', 'status' => '已联系',
        ]);
        $this->booking('重复留资客', '13900000052', '10:30', 'booked', ['course_type' => '3'], true);

        // 终态留资不参与联动（已成交 / 已流失）
        Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '已成单客', 'phone' => '13900000053',
            'source' => '小红书', 'venue' => '绿地店', 'service_teacher' => '', 'status' => '已成交',
        ]);
        $this->booking('已成单客', '13900000053', '11:30', 'booked', ['course_type' => '1'], true);

        // 留资体验课卡片（source=lead）：key 里带留资 id 与节次
        $leadTrial = Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '卡片体验客', 'phone' => '13900000054',
            'source' => '大众点评', 'venue' => '绿地店', 'service_teacher' => '', 'status' => '已约体验',
            'trial_cards' => [[
                'session' => 1, 'time' => now()->format('Y-m-d 15:00'), 'topic' => '体态评估',
                'teacher' => '老师B', 'kind' => '私教',
            ]],
        ]);

        $data = $this->getJson('/api/today/todo')->assertOk()->json('data');
        $ky = collect($data['trials'])->firstWhere('name', 'KY体验客');
        $this->assertNotNull($ky);
        $this->assertSame('普拉提私教', $ky['course']);
        $this->assertSame('小周', $ky['teacher']);
        $this->assertSame('私教', $ky['kind'], 'course_type=2 → 私教（与今日预约组同一套 KIND_LABELS）');
        $this->assertSame($linked->id, $ky['leadId'], '唯一命中的进行中留资必须回填');
        // 既有键不动
        $this->assertStringStartsWith('trial:ky-', $ky['key']);
        $this->assertSame('普拉提私教', $ky['topic']);
        $this->assertSame('ky', $ky['source']);
        $this->assertFalse($ky['done']);

        $ambiguous = collect($data['trials'])->firstWhere('name', '重复留资客');
        $this->assertNotNull($ambiguous);
        $this->assertSame('小班', $ambiguous['kind'], 'course_type=3 → 小班');
        $this->assertNull($ambiguous['leadId'], '同号多条进行中留资视为歧义，绝不联动');

        $closed = collect($data['trials'])->firstWhere('name', '已成单客');
        $this->assertNotNull($closed);
        $this->assertNull($closed['leadId'], '已成交的留资不参与联动');

        $leadItem = collect($data['trials'])->firstWhere('key', 'trial:lead-'.$leadTrial->id.'-1');
        $this->assertNotNull($leadItem);
        $this->assertSame('体态评估', $leadItem['course']);
        $this->assertSame('老师B', $leadItem['teacher']);
        $this->assertSame('私教', $leadItem['kind'], '卡片自带 kind 时按 KIND_LABELS 归一');
        $this->assertSame($leadTrial->id, $leadItem['leadId']);
        $this->assertSame(1, $leadItem['session']);
    }

    public function test_new_leads_and_reviews_carry_why_text(): void
    {
        Sanctum::actingAs($this->super());

        Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '新客小刘', 'phone' => '13900000061',
            'source' => '大众点评', 'venue' => '绿地店', 'service_teacher' => '', 'status' => '新留资',
            'created_at' => now()->subHours(2),
        ]);
        // 今天已签到、还没填课后分析的私教课（非体验课）
        $this->booking('课后会员', '13900000062', '14:00', 'signed', ['course_type' => '2'], false, '绿地店', 'private');

        $data = $this->getJson('/api/today/todo')->assertOk()->json('data');

        $lead = collect($data['newLeads'])->firstWhere('name', '新客小刘');
        $this->assertNotNull($lead);
        $this->assertSame('大众点评留资 2 小时未首响', $lead['whyText']);
        // 既有键不动
        $this->assertSame('lead:'.$lead['id'], $lead['key']);
        $this->assertFalse($lead['stale']);
        $this->assertFalse($lead['done']);

        $review = collect($data['reviews'])->firstWhere('name', '课后会员');
        $this->assertNotNull($review);
        $this->assertSame('今日私教课已签到，待填写课后分析', $review['whyText']);
        $this->assertSame('review:ky-'.$review['bookingId'], $review['key']);
        $this->assertSame('私教', $review['kind']);
        $this->assertFalse($review['isTrial']);
    }

    /**
     * reviews 组文案不许出现「课课」（t2 前端联调发现的中文瑕疵）。
     *
     * 课型标签里 `团课` 自带「课」字，旧写法 `'今日'.$kind.'课'` 会拼出「今日团课课已签到」。
     * 现在按词尾判断是否补「课」：`私教`/`小班` 补（今日私教课、今日小班课），
     * `团课` 不补（今日团课）。
     *
     * 注意本组的入口是「已签到 ∧（体验课 ∨ 私教课）」：非体验的团课根本进不来，
     * 所以「今日团课…」这一支目前是**防御性**的 —— 这里用体验团课（is_trial + group）
     * 把「任何能进本组的行都不许出现课课」钉死，将来放开入口也不会立刻退回旧文案。
     */
    public function test_reviews_why_text_never_doubles_the_word_ke(): void
    {
        Sanctum::actingAs($this->super());

        // 本组入口是「已签到 ∧（体验课 ∨ course_kind=private）」：非体验的团课/小班进不来，
        // 所以另两种课型用「体验课」形态进来，用来钉住它们的 kind 标签与「不许双课」
        $this->booking('私教课后', '13900000071', '13:00', 'signed', ['course_type' => '2'], false, '绿地店', 'private');
        $this->booking('小班体验课后', '13900000072', '13:30', 'signed', ['course_type' => '3'], true, '绿地店', 'small');
        $this->booking('团课体验课后', '13900000073', '15:00', 'signed', ['course_type' => '1'], true, '绿地店', 'group');

        $data = $this->getJson('/api/today/todo')->assertOk()->json('data');
        $reviews = collect($data['reviews'])->keyBy('name');

        $this->assertSame('今日私教课已签到，待填写课后分析', $reviews['私教课后']['whyText']);
        $this->assertSame('小班', $reviews['小班体验课后']['kind']);
        $this->assertSame('团课', $reviews['团课体验课后']['kind'], 'group → 团课（自带「课」字，正是双课风险的来源）');
        $this->assertSame('今日体验课已签到，待填写课后分析', $reviews['小班体验课后']['whyText']);
        $this->assertSame('今日体验课已签到，待填写课后分析', $reviews['团课体验课后']['whyText']);
        foreach ($reviews as $name => $item) {
            $this->assertStringNotContainsString('课课', (string) $item['whyText'], "「{$name}」的文案出现了双「课」");
        }
    }

    public function test_marking_a_card_done_keeps_key_and_trace_fields(): void
    {
        Sanctum::actingAs($this->super());

        Customer::create($this->customerAttributes('勾选会员', '13800000007', [
            'main_card' => '私教卡', 'remain_times' => 5, 'attend_m3' => 2,
            'expire_date' => now()->addDays(5)->toDateString(),
        ]));
        $booking = $this->booking('勾选会员', '13800000007', '16:00', 'booked', ['course_type' => '2']);

        $data = $this->getJson('/api/today/todo')->assertOk()->json('data');
        $item = collect($data['bookings']['items'])->firstWhere('memberName', '勾选会员');
        $this->assertFalse($item['done']);
        $this->assertArrayNotHasKey('doneBy', $item);

        $this->postJson('/api/today/todo/action', [
            'type' => 'bookings',
            'key' => $item['key'],
            'action' => '已确认',
            'remark' => '已电话确认到店',
        ])->assertOk();

        $done = collect($this->getJson('/api/today/todo')->assertOk()->json('data.bookings.items'))
            ->firstWhere('memberName', '勾选会员');
        $this->assertTrue($done['done']);
        $this->assertSame($item['key'], $done['key'], 'key 语义必须一字不变（勾选是按键落库的）');
        $this->assertSame('已确认', $done['doneAction']);
        $this->assertSame('超管', $done['doneBy']);
        $this->assertSame('已电话确认到店', $done['doneRemark']);
        // 新字段在勾选后依然在
        $this->assertSame(2, $done['attend']['m3']);
        $this->assertSame('私教卡 · 剩 5 节', $done['cardHint']);
        $this->assertSame('booking:'.$booking->id, $done['key']);
    }

    // ------------------------------------------------------------- 数据准备

    private function super(): User
    {
        return User::factory()->create(['username' => 'sup-enrich', 'name' => '超管', 'role' => 'R_SUPER', 'venue' => null]);
    }

    private function customerAttributes(string $name, string $phone, array $overrides): array
    {
        return array_merge([
            'name' => $name,
            'phone' => $phone,
            'phone_tail' => substr($phone, -4),
            'venue' => '绿地店',
            'source' => 'KeepYoga',
            'owner' => '绿地店长',
            'consultant' => '绿地店长',
            'main_card' => '—',
            'layer' => 'P4',
            'status' => '在籍',
        ], $overrides);
    }

    private function booking(
        string $memberName,
        string $phone,
        string $time,
        string $status,
        array $raw = [],
        bool $isTrial = false,
        string $venue = '绿地店',
        ?string $courseKind = null
    ): KyBooking {
        $attributes = [
            'source_key' => uniqid('enrich-'),
            'venue' => $venue,
            'booking_type' => ($raw['course_type'] ?? '1') === '2' ? '私教' : '团课',
            'member_id' => 'm-'.md5($memberName),
            'member_name' => $memberName,
            'phone' => $phone,
            'start_at' => date('Y-m-d').' '.$time.':00',
            'course_name' => $isTrial ? '普拉提私教' : '普拉提私教',
            'teacher_name' => '小周',
            'status_raw' => '',
            'status' => $status,
            'is_trial' => $isTrial,
            'raw' => $raw,
        ];
        // reviews 组是按 course_kind 列筛的（不是 raw.course_type），要进那一组就得给列值；
        // 列是 NOT NULL，所以只在显式传入时补，不写 null
        if ($courseKind !== null) {
            $attributes['course_kind'] = $courseKind;
        }

        return KyBooking::create($attributes);
    }
}
