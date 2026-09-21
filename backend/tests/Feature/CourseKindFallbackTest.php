<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\KyBooking;
use App\Models\User;
use App\Services\KyMemberSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 课型判定收口回归：`course_type` 缺失时，四个出口必须返回**同一个**课型。
 *
 * 缺陷背景：`course_type` 缺失时的兜底原本在四处各写一份，且方向相反 ——
 * 同步写入按接口来源兜底成 private、模型访问器按 booking_type 兜底成 private、
 * 今日预约兜底成团课、新客培养与看板兜底成 group。同一行预约数据在「课程事实 /
 * 今日预约 / 课后分析列表 / 新客培养 / 看板」上会显示不同课型。
 *
 * 现在映射与兜底都只在 `courseKindFrom()` 定义（helpers.php），本文件锁住两点：
 *  1. 缺失时各出口一致（跨出口一致性，缺陷本体）；
 *  2. 显式 `course_type` 的映射方向 = **1=group / 2=private（私教课，对客「定制私教」）
 *     / 3=small（精品课，对客「私教小班」）**。
 *     该方向被翻过两次：t35 依 `notes.md:426` 的 1/2/3 **顺序推断**误判为反向
 *     （并配了重算迁移 `2026_09_21_000003`，已随 v3.2.0/v3.2.1 发布），后经用户
 *     第一手确认翻回，由 `2026_09_21_000004` 重算纠正。完整证据链见
 *     `courseKindFrom()` 注释——谁再翻转谁红。
 */
class CourseKindFallbackTest extends TestCase
{
    use RefreshDatabase;

    /** 构造一行 `course_type` 缺失的预约事实（走真实同步解析路径） */
    private function factWithoutCourseType(string $path = 'course/api/queryreversionleague', array $overrides = []): array
    {
        $method = new \ReflectionMethod(KyMemberSyncService::class, 'bookingFact');
        $method->setAccessible(true);

        return $method->invoke(null, array_merge([
            'id' => '9001',
            'm_id' => 'M9001',
            'm_name' => '缺课型会员',
            'phone' => '13900009001',
            'start_time' => date('Y-m-d').' 10:00:00',
            'course_name' => '缺课型课程',
            'coach_name' => '绿地老师',
        ], $overrides), $path, '绿地店', '77');
    }

    /**
     * 出口 1（课程事实）：同步写入路径在 `course_type` 缺失时不得给出 private。
     *
     * private 是**授权判据**（`privateStudentKeys()` 取 `course_kind='private'`，
     * 被会员列表/客资可见性与客资详情读写当授权依据），兜底偏 private 会放大
     * 授课老师的可见范围，故取「不放大可见范围」的 group 侧。
     */
    public function test_booking_fact_falls_back_to_group_when_course_type_missing(): void
    {
        // 私教接口（queryreversionprivate）也上过小班行，原实现据此把无 course_type 的行
        // 当成私教；两条接口都必须收敛到同一口径，否则仍会按来源分叉
        $this->assertSame('group', $this->factWithoutCourseType('course/api/queryreversionleague')['course_kind']);
        $this->assertSame('group', $this->factWithoutCourseType('course/api/queryreversionprivate')['course_kind']);
    }

    /**
     * 显式 `course_type` 的映射方向（用户第一手确认后钉死，勿再翻转）。
     *
     * 1=团课（对客「精品团课」）/ 2=私教课（对客「定制私教」）→ 私教 /
     * 3=精品课（对客「私教小班」）→ 小班。
     * 该断言同时走**两条路径**（同步写入 `bookingFact()` 与 `courseKindFrom()`），
     * 避免只锁其中一处。
     */
    public function test_explicit_course_type_mapping_is_the_adjudicated_direction(): void
    {
        foreach (['1' => 'group', '2' => 'private', '3' => 'small'] as $code => $expected) {
            $this->assertSame(
                $expected,
                $this->factWithoutCourseType('course/api/queryreversionprivate', ['course_type' => (string) $code])['course_kind'],
                "course_type={$code} 的映射方向不对（应为 1=group/2=private/3=small）"
            );
            $this->assertSame($expected, \courseKindFrom((string) $code));
        }
    }

    /**
     * 方向护栏：t35 的误判映射（2=small / 3=private）必须**不再**成立。
     *
     * 单独立一条是为了让「谁把它改回去」以最直白的方式变红，
     * 而不是仅由上面那条的循环隐式覆盖。
     *
     * 背景：t35 依据 `notes.md:426` 的 1/2/3 顺序**推断**翻了方向，但同一次探索
     * 记录的实测例子（核心床｜上肢线条雕刻 type=3 = 精品课）与三处独立来源都指向
     * 本方向；用户已第一手确认。详见 `courseKindFrom()` 注释。
     */
    public function test_misjudged_reversed_mapping_is_gone(): void
    {
        $this->assertSame('private', \courseKindFrom('2'), '2=私教课（对客「定制私教」）→ 私教，不是小班');
        $this->assertSame('small', \courseKindFrom('3'), '3=精品课（对客「私教小班」）→ 小班，不是私教');
        $this->assertNotSame('small', \courseKindFrom('2'), 't35 误判映射（2=small）已被推翻，不得回潮');
        $this->assertNotSame('private', \courseKindFrom('3'), 't35 误判映射（3=private）已被推翻，不得回潮');
    }

    /** 缺失（含空串/未设置）一律 group，且两种缺失写法结果相同 */
    public function test_course_kind_from_treats_all_missing_forms_alike(): void
    {
        foreach ([null, '', '0', '9'] as $missing) {
            $this->assertSame('group', \courseKindFrom($missing), '缺失形式的兜底必须是 group（失败关闭侧）');
        }
    }

    /**
     * 出口 2/3/4（今日预约 / 课后分析列表 / 新客培养）：同一行数据必须显示同一课型。
     *
     * 一行 `course_type` 缺失的预约，同时出现在三个出口，断言它们不打架。
     */
    public function test_same_row_reports_identical_kind_across_exits(): void
    {
        // 该行靠 raw 兜底（模拟迁移前/未回填的历史行，course_kind 为空）
        $booking = KyBooking::create([
            'source_key' => '77:私教:fallback-1',
            'venue' => '绿地店',
            'booking_type' => '私教',
            'course_kind' => '',
            'member_id' => 'M9001',
            'member_name' => '缺课型会员',
            'phone' => '13900009001',
            'start_at' => now()->setTime(10, 0),
            'course_name' => '缺课型课程',
            'teacher_name' => '绿地老师',
            'status' => 'signed',
            'is_trial' => false,
            'raw' => ['coach_name' => '绿地老师'],
        ]);

        // 课后分析候选的「新入会会员」窗口与养成窗口都需要会员档案
        Customer::create([
            'name' => '缺课型会员',
            'phone' => '13900009001',
            'venue' => '绿地店',
            'external_id' => 'ky:77:M9001',
            'enrolled_at' => now()->subDays(10)->toDateString(),
            'layer' => 'P1',
        ]);

        Sanctum::actingAs(User::factory()->create(['username' => 'kind-fallback-super', 'role' => 'R_SUPER']));
        Cache::flush();

        // —— 出口：今日预约 ——
        $todo = $this->getJson('/api/today/todo')->assertOk()->json('data');
        $todayItem = collect($todo['bookings']['items'] ?? [])->firstWhere('memberName', '缺课型会员');
        $this->assertNotNull($todayItem, '今日预约出口没有返回该行');
        $todayKind = $todayItem['kind'];

        // —— 出口：课后分析列表 ——（候选接口把「上过课的」放在 records）
        $candidates = $this->getJson('/api/post-class-reviews/candidates?days=7')->assertOk()->json('data');
        $rows = $candidates['records'] ?? $candidates['items'] ?? $candidates;
        $reviewRow = collect($rows)->firstWhere('studentName', '缺课型会员');
        $this->assertNotNull($reviewRow, '课后分析出口没有返回该行');
        $reviewKind = $reviewRow['kind'];

        // —— 出口：新客培养 ——
        $cultivation = $this->getJson('/api/new-members/cultivation')->assertOk()->json('data');
        $record = collect($cultivation['items'] ?? $cultivation['records'] ?? $cultivation)
            ->firstWhere('name', '缺课型会员');
        $this->assertNotNull($record, '新客培养出口没有返回该会员');
        // 该行只有一条预约，且缺失课型 → 只会落在某一个分类里
        $cultivationKinds = [];
        foreach (['private' => '私教', 'small' => '小班', 'group' => '团课'] as $key => $label) {
            if (($record['categories'][$key]['signed'] ?? 0) > 0) {
                $cultivationKinds[] = $label;
            }
        }
        $this->assertCount(1, $cultivationKinds, '该行应只归入一个课型分类');
        $cultivationKind = $cultivationKinds[0];

        // —— 出口：看板 trends（私教/小班/团课三分统计） ——
        $today = now()->toDateString();
        $trends = $this->getJson("/api/analytics/trends?start={$today}&end={$today}")
            ->assertOk()->json('data.summary');
        // 该行缺失课型 → 只应计入团课，不得计入私教（私教口径会牵动授权与统计）
        $this->assertSame(1, $trends['groupBookingCount'] ?? null, '看板应把缺失课型的行计入团课');
        $this->assertSame(0, $trends['privateBookingCount'] ?? null, '看板不得把缺失课型的行计入私教');

        // —— 出口：课程事实（模型访问器，与上述出口同源） ——
        $factKind = KyBooking::KIND_LABELS[$booking->fresh()->courseKind()];

        $this->assertSame(
            [$factKind, $factKind, $factKind, $factKind],
            [$todayKind, $reviewKind, $cultivationKind, $factKind],
            "同一行数据在不同出口课型不一致：课程事实={$factKind} 今日预约={$todayKind} "
            ."课后分析={$reviewKind} 新客培养={$cultivationKind}"
        );
        $this->assertSame('团课', $factKind, '缺失 course_type 的行应兜底为团课（不放大可见范围的一侧）');
    }

    /**
     * 缺失 `course_type` 且 `booking_type='团课'` 的行：各出口必须一致（验收口径原文）。
     *
     * 这是原缺陷最尖锐的一格：旧模型访问器写「booking_type=团课 → group」，
     * 而旧今日预约**忽略** booking_type、只看 raw，两条路径在同一行上分叉。
     * 反向（booking_type='私教'）也一并锁住，避免只修一侧。
     */
    public function test_missing_course_type_tuan_booking_type_is_group_everywhere(): void
    {
        $booking = KyBooking::create([
            'source_key' => '77:团课:tuan-missing',
            'venue' => '绿地店',
            'booking_type' => '团课',
            'course_kind' => '',
            'member_id' => 'M6001',
            'member_name' => '团课缺课型会员',
            'phone' => '13900006001',
            'start_at' => now()->setTime(11, 0),
            'course_name' => '团课缺课型课程',
            'teacher_name' => '绿地老师',
            'status' => 'signed',
            'is_trial' => false,
            'raw' => ['coach_name' => '绿地老师'],
        ]);

        \App\Models\Customer::create([
            'name' => '团课缺课型会员',
            'phone' => '13900006001',
            'venue' => '绿地店',
            'external_id' => 'ky:77:M6001',
            'enrolled_at' => now()->subDays(5)->toDateString(),
            'layer' => 'P1',
        ]);

        Sanctum::actingAs(User::factory()->create(['username' => 'kind-fallback-super-tuan', 'role' => 'R_SUPER']));
        Cache::flush();

        // 课程事实（模型访问器）
        $this->assertSame('团课', KyBooking::KIND_LABELS[$booking->fresh()->courseKind()]);

        // 今日预约
        $todo = $this->getJson('/api/today/todo')->assertOk()->json('data');
        $this->assertSame(
            '团课',
            collect($todo['bookings']['items'] ?? [])->firstWhere('memberName', '团课缺课型会员')['kind'] ?? null,
            'booking_type=团课 且缺失 course_type 的行在今日预约出口应为团课'
        );

        // 新客培养
        $cultivation = $this->getJson('/api/new-members/cultivation')->assertOk()->json('data');
        $record = collect($cultivation['items'] ?? $cultivation['records'] ?? $cultivation)
            ->firstWhere('name', '团课缺课型会员');
        $this->assertNotNull($record, '新客培养出口没有返回该会员');
        $this->assertGreaterThan(0, $record['categories']['group']['signed'] ?? 0, '应为团课分类');
        $this->assertSame(0, $record['categories']['private']['signed'] ?? 0, '不得计入私教分类');

        // 反向：booking_type='私教' 也不能因为 booking_type 就变成私教
        KyBooking::where('source_key', '77:团课:tuan-missing')->update(['booking_type' => '私教']);
        $this->assertSame(
            'group',
            KyBooking::where('source_key', '77:团课:tuan-missing')->first()->courseKind(),
            'booking_type 不是课型判据：缺失 course_type 时不得因 booking_type=私教 就变成 private'
        );
    }

    /**
     * 授权侧回归：`course_type` 缺失的行**不得**把学员计入授课老师的私教学员，
     * 否则该老师会越权看到别人名下的会员/客资（`course_kind='private'` 是授权判据）。
     *
     * 走**真实同步写入路径**（bookingFact → 落库），而不是手工造 `course_kind=''` ——
     * 后者无论如何都不会等于 `'private'`，测不出兜底方向（旧实现也会通过）。
     */
    public function test_missing_course_type_does_not_widen_teacher_visibility(): void
    {
        $teacher = User::factory()->create([
            'username' => 'kind-fallback-teacher',
            'name' => '绿地老师',
            'role' => 'R_TEACHER',
            'venue' => '绿地店',
        ]);

        // 同步写入：私教接口（queryreversionprivate）上一条**没有** course_type 的行。
        // 旧实现按接口来源兜底成 private，这条就会变成该老师的「私教学员」授权键。
        $fact = $this->factWithoutCourseType('course/api/queryreversionprivate', [
            'id' => '7777',
            'm_id' => 'M7777',
            'm_name' => '课型未知会员',
            'phone' => '13900007777',
        ]);
        KyBooking::create($fact);

        $this->assertSame(
            'group',
            (string) KyBooking::where('member_id', 'M7777')->value('course_kind'),
            '缺失 course_type 的行落库时不得是 private（private 会放大老师的可见范围）'
        );

        Sanctum::actingAs($teacher);
        Cache::flush();

        $keys = \privateStudentKeys($teacher);
        $this->assertSame([], $keys['external_ids'], '课型未知的行不得进入私教学员授权键（会放大可见范围）');
        $this->assertSame([], $keys['phones'], '课型未知的行不得进入私教学员授权键（会放大可见范围）');
    }

    /**
     * 【t35 最重要的一条】授权语义回归：修正映射后，授课老师的「我的学员」
     * **只含其真实私教学员**（`course_type=2`，对客「定制私教」），
     * **绝不含小班学员**（`course_type=3`，对客「私教小班」）。
     *
     * 为什么这是本任务的核心安全面：`course_kind='private'` 是
     * `privateStudentKeys()` 的**按人隔离授权判据**，其返回值再被
     *  - `scopeCustomersForUser()`（会员/客资可见范围）、
     *  - `EnsureUserIsEnabled`（客资详情读写准入）、
     *  - `privateTeaches()`
     * 当授权依据使用。
     *
     * 映射反向时的真实后果（不只是「看不到」，而是**越权可见**）：
     * 老师被算作小班学员的「私教学员」⇒ 能看到**别人的**学员档案，
     * 同时自己的真实私教学员反而进不了授权键。
     *
     * 本用例走**真实同步写入路径**（`bookingFact()` → 落库），确保验证的是
     * 「同步怎么算课型」而不是人造列值。
     */
    public function test_authz_teacher_private_students_exclude_small_class_students(): void
    {
        $teacher = User::factory()->create([
            'username' => 't35-teacher',
            'name' => '定制私教老师',
            'role' => 'R_TEACHER',
            'venue' => '绿地店',
        ]);

        // 同一老师、同一门店、都是 signed，唯一差别是 course_type：
        //   2=私教课（对客「定制私教」）→ 应进授权键
        //   3=精品课（对客「私教小班」）→ 绝不可进授权键
        // 注意必须带 status 描述符：bookingStatus() 只认「已签到/签到/已完成」→signed，
        // 否则落库为 unknown，而 privateStudentKeys() 只取 status='signed' 的行。
        $private = $this->factWithoutCourseType('course/api/queryreversionprivate', [
            'id' => '3501', 'm_id' => 'M3501', 'm_name' => '真私教学员',
            'phone' => '13900003501', 'course_type' => '2', 'coach_name' => '定制私教老师',
            'status_desc' => '已签到',
        ]);
        $small = $this->factWithoutCourseType('course/api/queryreversionprivate', [
            'id' => '3502', 'm_id' => 'M3502', 'm_name' => '小班学员',
            'phone' => '13900003502', 'course_type' => '3', 'coach_name' => '定制私教老师',
            'status_desc' => '已签到',
        ]);

        KyBooking::create($private);
        KyBooking::create($small);

        // 落库口径先钉住（防止测试通过只是因为两条都算成了 group）
        $this->assertSame('private', (string) KyBooking::where('member_id', 'M3501')->value('course_kind'), 'course_type=2 必须落 private');
        $this->assertSame('small', (string) KyBooking::where('member_id', 'M3502')->value('course_kind'), 'course_type=3 必须落 small');

        Sanctum::actingAs($teacher);
        Cache::flush();

        $keys = \privateStudentKeys($teacher);

        // ① 只含真实私教学员
        $this->assertContains('ky:77:M3501', $keys['external_ids'], '真实私教学员必须在授权键里（否则老师「我的学员」为空）');

        // 手机号通道：t36 已修掉「PHP 把纯数字字符串键强转成 int ⇒ int 元素 vs string
        // 严格比较恒 false」的既有缺陷（详见 StaffRenameRegressionTest 第 5 节的用例）。
        // 因此这里直接断言**严格比较命中**，并顺带钉住元素类型 —— 不再需要 strval 绕行
        // （原先绕行是为了规避缺陷，现在若还绕行，就等于把这个回归漏测掉）。
        $this->assertContains('13900003501', $keys['phones'], '真实私教学员的手机号应在键集合里');
        $this->assertNotContains('13900003502', $keys['phones'], '小班学员手机号不得作为会员/客资可见判据');
        $this->assertTrue(
            in_array('13900003501', $keys['phones'], true),
            '手机号通道必须是 string 严格比较可命中的（否则老师静默漏看自己的学员，即 t36 修掉的缺陷）'
        );
        foreach ($keys['phones'] as $i => $p) {
            $this->assertIsString($p, "phones[{$i}] 必须是 string（int 说明数字键强转又回来了）");
        }

        // ② 绝不含小班学员 —— 这是越权面
        $this->assertNotContains('ky:77:M3502', $keys['external_ids'], '小班学员（course_type=3）不得进入私教学员授权键：那是越权可见他人学员');
        $this->assertCount(1, $keys['external_ids'], '授权键应恰好只含 1 名真实私教学员');
        $this->assertCount(1, $keys['phones'], '授权键手机号应恰好只含 1 名真实私教学员');
    }

    /**
     * 同一格的**反向**护栏：t35 误判映射下这两条断言恰好相反（小班学员被算成私教），
     * 因此本用例能真实捕捉「映射回潮」，不是同义反复。
     *
     * 直接对 `courseKindFrom()` 断言两条课型的归属，并在注释里写明误判值，
     * 便于后人一眼看出方向。
     */
    public function test_small_class_is_not_treated_as_private_student(): void
    {
        // course_type=3 → small（t35 误判：private ← 就是那个越权方向）
        $this->assertSame('small', \courseKindFrom('3'));
        // course_type=2 → private（t35 误判：small ← 老师自己的学员反而看不见）
        $this->assertSame('private', \courseKindFrom('2'));

        // 只有 2 能产出 private：遍历全部显式值，private 的唯一来源是 '2'
        $privateSources = array_filter(
            ['1', '2', '3'],
            fn ($code) => \courseKindFrom($code) === 'private'
        );
        $this->assertSame(['2'], array_values($privateSources), 'private 只能由 course_type=2 产出（授权判据的唯一来源）');
    }
}
