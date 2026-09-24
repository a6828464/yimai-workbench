<?php

namespace Tests\Feature;

use App\Models\KyBooking;
use App\Models\PayrollProfile;
use App\Models\User;
use App\Services\KyClient;
use App\Services\KyCourseRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 老师课时统计口径（`GET /payroll/hours`）。
 *
 * 这个出口最容易静默算错的地方有三处，本测试逐条锁住：
 *
 * 1. **`ky_bookings` 一行 = 一条会员预约，不是一节课**。一节课 N 个会员 = N 行，
 *    直接 `count(*)` 会把课时放大 1.5~1.9 倍，连带把底薪奖励的 80/100/110/120 档位判错。
 *    所以必须按课次去重。
 * 2. **只算已签到**（`status='signed'`，与 `TodayController:171`、
 *    `KyMemberSyncService:540` 同口径），且**排除体验课**（`is_trial=true`）。
 * 3. **45/60 分钟只对私教解析**：时长写在课程名里（`VIP定制私教｜45Min`）。
 *    小班/团课是单一价、没有时长概念，不能被判成「未含时长」而报假警报。
 * 4. **课时费必须双源核验**（v3.3.4）：课时记录（`course/api/getcoursesummaryrecordstat`）
 *    与预约记录（`ky_bookings`）并列，差额显式暴露。取数有两个上游陷阱
 *    （日期参数名必须是 `start`/`end`；默认只返回 15 行），见 `KyCourseRecordService`。
 *
 * ## 绝不真实联网
 *
 * 本文件所有涉及上游的用例都走 {@see Http::fake()}：`setUp()` 里先 `preventStrayRequests()`
 * 兜底 —— 任何没被 fake 命中的请求会**抛异常**而不是打出去。涉及课时记录的用例还会
 * 显式 `Http::fake([... getcoursesummaryrecordstat => ...])`。
 * 冻结夹具是 `tests/fixtures/hours_two_source_202609.json`（**已脱敏**，见该文件的 `_comment`）。
 */
class PayrollHoursTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 兜底防线：没被 fake 命中的请求一律抛异常，**不会**打到真实上游。
        // 这条让「忘了 fake 某个新接口」在测试里立刻炸出来，而不是偷偷联网。
        Http::preventStrayRequests();
    }

    private function super(): User
    {
        return User::factory()->create([
            'name' => '超管', 'username' => 'payroll-super', 'role' => 'R_SUPER',
            'roles' => ['R_SUPER'], 'venue' => null, 'venues' => ['绿地店', '东部店'], 'status' => '启用',
        ]);
    }

    private function teacher(string $name, string $venue, string $role = '全职老师', array $extra = []): PayrollProfile
    {
        return PayrollProfile::create(array_merge([
            'name' => $name, 'venue' => $venue, 'role' => $role,
            'base_salary' => 4000, 'performance' => 0,
            'fee_private60' => 160, 'fee_private45' => 0,
            'fee_small' => 160, 'fee_group' => 160, 'fee_enterprise' => 0,
            'status' => '有效',
        ], $extra));
    }

    /** 造一条预约行（同一课次可造多行，模拟「一节课 N 个会员」） */
    private function booking(string $venue, string $teacher, string $startAt, string $kind, array $extra = []): KyBooking
    {
        static $seq = 0;
        $seq++;

        return KyBooking::create(array_merge([
            'source_key' => "test:bk:{$seq}",
            'venue' => $venue,
            'booking_type' => '团课',
            'course_kind' => $kind,
            'member_id' => 'm'.$seq,
            'member_name' => '会员'.$seq,
            'phone' => '1380000'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
            'start_at' => $startAt,
            'course_name' => '私教课',
            'teacher_name' => $teacher,
            'status_raw' => '已签到',
            'status' => 'signed',
            'is_trial' => false,
            'raw' => ['demo' => true],
        ], $extra));
    }

    public function test_未登录返回401(): void
    {
        $this->getJson('/api/payroll/hours?month=2026-08')->assertStatus(401);
    }

    public function test_非超管返回403(): void
    {
        foreach (['R_MANAGER', 'R_SERVICE', 'R_TEACHER', 'R_MEDIA'] as $role) {
            $u = User::factory()->create([
                'name' => '普通'.$role, 'username' => 'u-'.$role, 'role' => $role,
                'roles' => [$role], 'venue' => '绿地店', 'venues' => ['绿地店'], 'status' => '启用',
            ]);
            Sanctum::actingAs($u);
            $this->getJson('/api/payroll/hours?month=2026-08')->assertStatus(403);
            $this->getJson('/api/payroll/calculate?month=2026-08')->assertStatus(403);
        }
    }

    /**
     * 一节课 3 个会员 = 3 行，但只能算 **1 节**。
     *
     * 这是本模块最关键的一条：`ky_bookings` 存的是会员预约行，不是课次。
     */
    public function test_同一课次多会员预约只算一节(): void
    {
        Sanctum::actingAs($this->super());
        $this->teacher('张情', '绿地店');

        // 同一老师、同一时刻、同课型 → 3 条会员预约 = 1 节课
        foreach ([1, 2, 3] as $i) {
            $this->booking('绿地店', '张情', '2026-08-05 10:00:00', 'private', ['member_name' => "会员{$i}"]);
        }
        // 另一节课（不同时刻）
        $this->booking('绿地店', '张情', '2026-08-06 10:00:00', 'private');

        $res = $this->getJson('/api/payroll/hours?month=2026-08')->assertOk()->json('data');
        $row = collect($res['rows'])->firstWhere('name', '张情');

        $this->assertSame(2, $row['private60'], '两节课次 → 2 节，不能是 4 行');
        $this->assertSame(4, $row['bookingRows'], '预约行数仍应如实回显，供核对');
        $this->assertSame(2, $row['classCount']);
        $this->assertSame(2, $row['totalHours']);
    }

    /** 口径透明：响应必须回显去重键与理由，让用户能自己核对「行数 vs 课次」 */
    public function test_响应回显去重键与行数课次对照(): void
    {
        Sanctum::actingAs($this->super());
        $this->teacher('张情', '绿地店');
        $this->booking('绿地店', '张情', '2026-08-05 10:00:00', 'private');
        $this->booking('绿地店', '张情', '2026-08-05 10:00:00', 'private');

        $data = $this->getJson('/api/payroll/hours?month=2026-08')->assertOk()->json('data');
        $this->assertSame(['venue', 'teacher_name', 'start_at', 'course_kind'], $data['meta']['dedupeKey']);
        $this->assertNotEmpty($data['meta']['dedupeKeyReason']);
        $this->assertSame(1, $data['meta']['classCount']);
        $this->assertSame(2, $data['meta']['bookingRows']);
    }

    public function test_排除体验课(): void
    {
        Sanctum::actingAs($this->super());
        $this->teacher('张情', '绿地店');

        $this->booking('绿地店', '张情', '2026-08-05 10:00:00', 'private');
        $this->booking('绿地店', '张情', '2026-08-06 10:00:00', 'private', ['is_trial' => true]);

        $row = collect($this->getJson('/api/payroll/hours?month=2026-08')->json('data.rows'))
            ->firstWhere('name', '张情');
        $this->assertSame(1, $row['totalHours'], '体验课必须排除');
    }

    public function test_只统计已签到(): void
    {
        Sanctum::actingAs($this->super());
        $this->teacher('张情', '绿地店');

        $this->booking('绿地店', '张情', '2026-08-05 10:00:00', 'private');
        foreach (['booked', 'cancelled', 'no_show', 'unknown'] as $i => $status) {
            $this->booking('绿地店', '张情', '2026-08-0'.(6 + $i).' 10:00:00', 'private', ['status' => $status]);
        }

        $row = collect($this->getJson('/api/payroll/hours?month=2026-08')->json('data.rows'))
            ->firstWhere('name', '张情');
        $this->assertSame(1, $row['totalHours'], '只有 signed 算已上课');
    }

    public function test_按自然月区间过滤(): void
    {
        Sanctum::actingAs($this->super());
        $this->teacher('张情', '绿地店');
        $this->booking('绿地店', '张情', '2026-07-31 23:59:00', 'private');
        $this->booking('绿地店', '张情', '2026-08-01 00:00:00', 'private');
        $this->booking('绿地店', '张情', '2026-08-31 23:59:00', 'private');
        $this->booking('绿地店', '张情', '2026-09-01 00:00:00', 'private');

        $row = collect($this->getJson('/api/payroll/hours?month=2026-08')->json('data.rows'))
            ->firstWhere('name', '张情');
        $this->assertSame(2, $row['totalHours']);
    }

    public function test_按门店过滤且累计课时恒为两店(): void
    {
        Sanctum::actingAs($this->super());
        $this->teacher('张情', '绿地店');
        $this->booking('绿地店', '张情', '2026-08-05 10:00:00', 'private');
        $this->booking('东部店', '张情', '2026-08-06 10:00:00', 'private');

        // 只看绿地店
        $row = collect($this->getJson('/api/payroll/hours?month=2026-08&venue='.rawurlencode('绿地店'))->json('data.rows'))
            ->firstWhere('name', '张情');
        $this->assertSame(1, $row['totalHours'], '按门店过滤后只算本店');
        $this->assertSame(2, $row['accumulatedHours'], '两店累计课时不受 venue 过滤影响');

        // 两店合并
        $all = collect($this->getJson('/api/payroll/hours?month=2026-08')->json('data.rows'))
            ->firstWhere('name', '张情');
        $this->assertSame(2, $all['totalHours']);
    }

    /**
     * 45/60 分钟：时长写在课程名里（用户第一手确认 + 仓库内实证）。
     *
     * 用例清单取自 `随心瑜后台解读/随心瑜后台完整解读.md:266`（`VIP定制私教｜45Min`）、
     * `notes.md:472`（`VIP定制私教｜60Min`）、`notes.md:105`（`全能私教45Min`、`【线上】45min私教`）。
     */
    public function test_课程名时长解析覆盖11种写法(): void
    {
        $cases = [
            // [课程名, 期望分钟]
            ['VIP定制私教｜45Min', 45],
            ['VIP定制私教｜60Min', 60],
            ['VIP定制私教|45Min', 45],
            ['VIP定制私教|60min', 60],
            ['全能私教45Min', 45],
            ['全能私教60Min', 60],
            ['【线上】45min私教', 45],
            ['【线上】60MIN私教', 60],
            ['私教课45分钟', 45],
            ['私教课60分钟', 60],
            ['私教课45分', 45],
        ];
        foreach ($cases as [$name, $expected]) {
            $this->assertSame(
                $expected,
                \App\Services\PayrollService::minutesFromCourseName($name),
                "课程名「{$name}」应解析为 {$expected} 分钟"
            );
        }
    }

    /**
     * course_kind 三分正确，**方向严禁翻转**（1=团课 / 2=私教课 / 3=精品课→small）。
     *
     * 映射的唯一定义处在 `helpers.php` 的 `courseKindFrom()`（t35 曾误判为反向，
     * 已由用户第一手确认翻回）。薪酬侧**只读 `course_kind` 列**，不再写第二套映射 ——
     * 本用例锁住「读列即得正确课型」这一行为，防止有人在薪酬侧重算一遍并搞反方向。
     */
    public function test_course_kind三分方向不翻转(): void
    {
        // 底层映射本身的方向
        $this->assertSame('group', courseKindFrom('1'), '1=团课');
        $this->assertSame('private', courseKindFrom('2'), '2=私教课');
        $this->assertSame('small', courseKindFrom('3'), '3=精品课→小班');
        // 反向断言：搞反的话这两条会失败
        $this->assertNotSame('private', courseKindFrom('1'));
        $this->assertNotSame('small', courseKindFrom('2'));

        Sanctum::actingAs($this->super());
        $this->teacher('张情', '绿地店');
        $this->booking('绿地店', '张情', '2026-08-05 10:00:00', 'group', ['course_name' => '精品团课']);
        $this->booking('绿地店', '张情', '2026-08-06 10:00:00', 'private', ['course_name' => 'VIP定制私教｜60Min']);
        $this->booking('绿地店', '张情', '2026-08-07 10:00:00', 'small', ['course_name' => '精品小班']);

        $row = collect($this->getJson('/api/payroll/hours?month=2026-08')->json('data.rows'))
            ->firstWhere('name', '张情');

        $this->assertSame(1, $row['group'], '团课进 group');
        $this->assertSame(1, $row['private60'], '私教进 private60');
        $this->assertSame(1, $row['small'], '精品课进 small（不得进 private）');
        $this->assertSame(0, $row['private45'], '私教档不得被小班/团课污染');
    }

    /**
     * 14 条 `course_kind × course_name` 组合（t2 用例集）。
     *
     * 覆盖：三种课型 × 各类时长写法，以及**小班/团课带时长也不解析**这一关键约束。
     *
     * @dataProvider 时长用例
     */
    public function test_课时用例矩阵(string $kind, string $courseName, ?int $expect45, ?int $expect60, string $expectSource): void
    {
        Sanctum::actingAs($this->super());
        $this->teacher('张情', '绿地店');
        $this->booking('绿地店', '张情', '2026-08-05 10:00:00', $kind, ['course_name' => $courseName]);

        $data = $this->getJson('/api/payroll/hours?month=2026-08')->assertOk()->json('data');
        $row = collect($data['rows'])->firstWhere('name', '张情');

        if ($expect45 !== null) {
            $this->assertSame($expect45, $row['private45'], "[{$kind}]「{$courseName}」应落 private45");
        }
        if ($expect60 !== null) {
            $this->assertSame($expect60, $row['private60'], "[{$kind}]「{$courseName}」应落 private60");
        }
        if ($kind !== 'private') {
            // 小班/团课不解析时长、不标 assumed_60
            $this->assertSame(0, $row['assumed60Count'], "[{$kind}] 不应被标成估算");
            $this->assertSame(1, $row[$kind === 'small' ? 'small' : 'group']);
            $this->assertNull(
                collect($data['warnings'])->firstWhere('code', 'DURATION_ASSUMED_60'),
                "[{$kind}] 不应产生 DURATION_ASSUMED_60 假警报"
            );
        } else {
            $this->assertSame($expectSource, $row['durationSource'], "[private]「{$courseName}」时长来源");
        }
    }

    public static function 时长用例(): array
    {
        return [
            // ---- 私教：时长写法全覆盖（name_regex 主路径） ----
            '私教·全角45Min' => ['private', 'VIP定制私教｜45Min', 1, 0, 'name_regex'],
            '私教·全角60Min' => ['private', 'VIP定制私教｜60Min', 0, 1, 'name_regex'],
            '私教·半角45Min' => ['private', 'VIP定制私教|45Min', 1, 0, 'name_regex'],
            '私教·半角小写60min' => ['private', 'VIP定制私教|60min', 0, 1, 'name_regex'],
            '私教·数字紧跟45Min' => ['private', '全能私教45Min', 1, 0, 'name_regex'],
            '私教·数字紧跟60Min' => ['private', '全能私教60Min', 0, 1, 'name_regex'],
            '私教·括号前缀45min' => ['private', '【线上】45min私教', 1, 0, 'name_regex'],
            '私教·大写60MIN' => ['private', '【线上】60MIN私教', 0, 1, 'name_regex'],
            '私教·中文45分钟' => ['private', '私教课45分钟', 1, 0, 'name_regex'],
            '私教·中文60分钟' => ['private', '私教课60分钟', 0, 1, 'name_regex'],
            '私教·中文45分' => ['private', '私教课45分', 1, 0, 'name_regex'],
            // ---- 私教：未含时长 → 降级 60 且如实标注 ----
            '私教·无时长' => ['private', '私教课', 0, 1, 'assumed_60'],
            '私教·30分钟不在白名单' => ['private', '高分体式30分钟', 0, 1, 'assumed_60'],
            // ---- 小班/团课：单一价，不解析时长、不报假警报 ----
            '团课·带90Min不解析' => ['group', '精品团课｜90Min', null, null, ''],
            '团课·带60分钟不解析' => ['group', '团课60分钟', null, null, ''],
            '小班·无时长' => ['small', '精品小班', null, null, ''],
            '小班·带45Min不解析' => ['small', '私教小班｜45Min', null, null, ''],
        ];
    }

    /** 白名单只认 45/60：其他数字视为「未含时长」，不得按最接近档位猜 */
    public function test_非4560的数字不算时长(): void
    {
        foreach (['高分体式30分钟', '精品团课｜90Min', '私教课120分钟'] as $name) {
            $this->assertNull(
                \App\Services\PayrollService::minutesFromCourseName($name),
                "「{$name}」不是 45/60 档，应视为未含时长"
            );
        }
    }

    /** 课程名含 45Min 的私教 → 落 private45 档，而不是 private60 */
    public function test_45分钟私教落45档(): void
    {
        Sanctum::actingAs($this->super());
        $this->teacher('张情', '绿地店');
        $this->booking('绿地店', '张情', '2026-08-05 10:00:00', 'private', ['course_name' => 'VIP定制私教｜45Min']);
        $this->booking('绿地店', '张情', '2026-08-06 10:00:00', 'private', ['course_name' => 'VIP定制私教｜60Min']);

        $row = collect($this->getJson('/api/payroll/hours?month=2026-08')->json('data.rows'))
            ->firstWhere('name', '张情');
        $this->assertSame(1, $row['private45']);
        $this->assertSame(1, $row['private60']);
        $this->assertSame('name_regex', $row['durationSource']);
    }

    /**
     * 课程名不含时长 → 降级 60 但**必须如实标注并汇总**，不得静默按 60。
     *
     * 现有数据里 `course_name='私教课'`（无时长）就有 270 行，真实数据里也一定存在。
     */
    public function test_私教课名不含时长时标注assumed60并汇总(): void
    {
        Sanctum::actingAs($this->super());
        $this->teacher('张情', '绿地店');
        $this->booking('绿地店', '张情', '2026-08-05 10:00:00', 'private', ['course_name' => '私教课']);
        $this->booking('绿地店', '张情', '2026-08-06 10:00:00', 'private', ['course_name' => 'VIP定制私教｜45Min']);

        $data = $this->getJson('/api/payroll/hours?month=2026-08')->assertOk()->json('data');
        $row = collect($data['rows'])->firstWhere('name', '张情');

        $this->assertSame(1, $row['assumed60Count'], '只有那节无时长的私教被标为估算');
        $this->assertArrayHasKey('assumed_60', $row['durationSources']);

        $w = collect($data['warnings'])->firstWhere('code', 'DURATION_ASSUMED_60');
        $this->assertNotNull($w, '必须给出 DURATION_ASSUMED_60 汇总警告');
        $this->assertSame(1, $w['count']);
        $this->assertStringContainsString('私教', $w['message'], '措辞须写明是私教，避免被读成全课型');
    }

    /**
     * 小班/团课**不解析时长、不标 assumed_60**。
     *
     * 引擎里小班/团课是单一价（`hs['私教小班']*p['小班']`），没有 45/60 之分。
     * 若全课型扫，`精品团课｜90Min` 会被判成「未含时长」，让用户看到一堆假警报。
     */
    public function test_小班团课不参与时长解析也不报假警报(): void
    {
        Sanctum::actingAs($this->super());
        $this->teacher('张情', '绿地店');
        $this->booking('绿地店', '张情', '2026-08-05 10:00:00', 'group', ['course_name' => '精品团课｜90Min']);
        $this->booking('绿地店', '张情', '2026-08-06 10:00:00', 'small', ['course_name' => '精品小班']);
        $this->booking('绿地店', '张情', '2026-08-07 10:00:00', 'group', ['course_name' => '团课60分钟']);

        $data = $this->getJson('/api/payroll/hours?month=2026-08')->assertOk()->json('data');
        $row = collect($data['rows'])->firstWhere('name', '张情');

        $this->assertSame(0, $row['assumed60Count'], '团课/小班不该被标成估算');
        $this->assertSame(2, $row['group']);
        $this->assertSame(1, $row['small']);
        $this->assertNull(
            collect($data['warnings'])->firstWhere('code', 'DURATION_ASSUMED_60'),
            '没有私教估算时不应出现该警告'
        );
    }

    /** `assumed_60` 汇总必须带 affectedUsers 明细，口径写明是「私教」 */
    public function test_assumed60汇总带受影响人员明细(): void
    {
        Sanctum::actingAs($this->super());
        $this->teacher('张情', '绿地店');
        $this->teacher('徐秀娟', '绿地店');
        // 张情 2 节无时长，徐秀娟 1 节无时长
        $this->booking('绿地店', '张情', '2026-08-05 10:00:00', 'private', ['course_name' => '私教课']);
        $this->booking('绿地店', '张情', '2026-08-06 10:00:00', 'private', ['course_name' => '定制私教']);
        $this->booking('绿地店', '徐秀娟', '2026-08-07 10:00:00', 'private', ['course_name' => '私教课']);
        // 团课无时长不参与统计
        $this->booking('绿地店', '张情', '2026-08-08 10:00:00', 'group', ['course_name' => '精品团课']);

        $w = collect($this->getJson('/api/payroll/hours?month=2026-08')->json('data.warnings'))
            ->firstWhere('code', 'DURATION_ASSUMED_60');

        $this->assertNotNull($w);
        $this->assertSame(3, $w['count'], '只数私教课中未含时长的节数（团课不算）');
        $this->assertArrayHasKey('affectedUsers', $w, '必须给出受影响人员明细');

        $byName = collect($w['affectedUsers'])->keyBy('name');
        $this->assertSame(2, $byName['张情']['assumed60Count']);
        $this->assertSame(1, $byName['徐秀娟']['assumed60Count']);
        // 明细要带样课程名，用户才知道该去改哪节课
        $this->assertContains('私教课', $byName['张情']['sampleCourses']);
        $this->assertContains('私教课', $byName['徐秀娟']['sampleCourses']);
    }

    /**
     * 课时统计 SQL 必须兼容 SQLite。
     *
     * SQLite **不支持多列** `COUNT(DISTINCT a, b)`（实测报
     * `wrong number of arguments to function count()`）。本实现走
     * `group by (venue, teacher_name, start_at, course_kind)` + `count(*)`，
     * 与「`count(*) from (select distinct …)` 子查询」等价，且能顺带取到
     * `min(course_name)`（时长解析要用）与 `count(*)`（行数/课次对照要用）。
     */
    public function test_课时统计SQL兼容sqlite(): void
    {
        Sanctum::actingAs($this->super());
        $this->teacher('张情', '绿地店');
        $this->booking('绿地店', '张情', '2026-08-05 10:00:00', 'private');

        $sql = \App\Models\KyBooking::query()
            ->select(['venue', 'teacher_name', 'start_at', 'course_kind',
                \Illuminate\Support\Facades\DB::raw('min(course_name) as course_name'),
                \Illuminate\Support\Facades\DB::raw('count(*) as row_count')])
            ->where('status', 'signed')->where('is_trial', false)
            ->whereBetween('start_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->whereNotNull('teacher_name')->where('teacher_name', '!=', '')
            ->groupBy(['venue', 'teacher_name', 'start_at', 'course_kind'])
            ->toSql();

        // 不得出现多列 COUNT(DISTINCT a, b) —— SQLite 会直接报错
        $this->assertDoesNotMatchRegularExpression(
            '/count\s*\(\s*distinct\s+[^)]*,[^)]*\)/i',
            $sql,
            '不得使用多列 COUNT(DISTINCT a, b)（SQLite 不支持）'
        );
        $this->assertStringContainsString('group by', strtolower($sql));

        // 真跑一次，证明在测试库（SQLite）上可执行
        $this->getJson('/api/payroll/hours?month=2026-08')->assertOk();
    }

    /** raw 起止差值作为次级来源（真实同步数据可能带 start_time/end_time） */
    public function test_raw起止差值可作为时长来源(): void
    {
        Sanctum::actingAs($this->super());
        $this->teacher('张情', '绿地店');
        $this->booking('绿地店', '张情', '2026-08-05 10:00:00', 'private', [
            'course_name' => '私教课',
            'raw' => ['start_time' => '10:00', 'end_time' => '10:45'],
        ]);

        $row = collect($this->getJson('/api/payroll/hours?month=2026-08')->json('data.rows'))
            ->firstWhere('name', '张情');
        $this->assertSame(1, $row['private45']);
        $this->assertSame('raw_end_time', $row['durationSource']);
    }

    /** 姓名走别名解析：`冰璐 → 钱冰璐`（精确匹配，禁止 LIKE %婷婷%） */
    public function test_授课老师姓名经别名解析到档案(): void
    {
        Sanctum::actingAs($this->super());
        $profile = $this->teacher('钱冰璐', '绿地店', '专职老师');
        $profile->aliases = ['冰璐']; $profile->save();

        $this->booking('绿地店', '冰璐', '2026-08-05 10:00:00', 'private');

        $row = collect($this->getJson('/api/payroll/hours?month=2026-08')->json('data.rows'))
            ->firstWhere('name', '钱冰璐');
        $this->assertNotNull($row, '别名「冰璐」应解析到「钱冰璐」');
        $this->assertSame(['冰璐'], $row['sourceNames']);
        $this->assertSame(1, $row['totalHours']);
    }

    /** 对不上档案的老师**不静默丢弃**，单列并给警告 */
    public function test_对不上档案的老师单独列出并给警告(): void
    {
        Sanctum::actingAs($this->super());
        $this->booking('绿地店', '查无此人', '2026-08-05 10:00:00', 'private');

        $data = $this->getJson('/api/payroll/hours?month=2026-08')->assertOk()->json('data');
        $w = collect($data['warnings'])->firstWhere('code', 'TEACHER_UNRESOLVED');
        $this->assertNotNull($w);
        $this->assertContains('查无此人', $w['names']);
    }

    /** 月份格式校验 */
    public function test_月份格式非法返回422(): void
    {
        Sanctum::actingAs($this->super());
        foreach (['2026-13', '2026/08', '202608', ''] as $bad) {
            $this->getJson('/api/payroll/hours?month='.$bad)->assertStatus(422);
        }
    }

    /** 门店枚举校验 */
    public function test_门店非法返回422(): void
    {
        Sanctum::actingAs($this->super());
        $res = $this->getJson('/api/payroll/hours?month=2026-08&venue='.rawurlencode('南山店'))->assertStatus(422);
        $this->assertSame('INVALID_VENUE', $res->json('code'));
    }

    // ==================================================================
    // 双源核验：课时记录（course/api/getcoursesummaryrecordstat）
    //          vs 预约记录（ky_bookings ← queryreversionleague/private）
    //
    // 全部走 Http::fake + 冻结夹具，**绝不真实联网**：
    // 夹具在 tests/fixtures/hours_two_source_202609.json（脱敏），
    // 数值逐字取自 captain 的只读侦察冻结样本。
    // ==================================================================

    /**
     * 夹具装载 + 上游伪造。
     *
     * 关键：夹具是**上游原始响应形态**（`data.course[]`，每行 = 一个 (老师,课程)），
     * 不是「已经算好的合计」—— 否则测试只验证了「算术」而没验证「取数」。
     *
     * @param  array<string,array<int,array>>  $rowsByVenueId  venue_id => data.course 行
     * @param  array<string,int>  $dates 期望的日期参数（start/end），留空则不校验
     */
    private function fakeCourseRecord(array $rowsByVenueId, bool $expectDateParams = true): void
    {
        config([
            'services.ky.phone' => '13800000000',
            'services.ky.password' => 'test-password',
        ]);

        Http::fake([
            KyClient::BASE.'/passport/api/login' => Http::response([
                'errno' => '0', 'data' => ['access_token' => 'test-token'],
            ]),
            KyClient::BASE.'/course/api/getcoursesummaryrecordstat' => function (Request $request) use ($rowsByVenueId, $expectDateParams) {
                $form = $request->data();

                // ---- 四个参数齐传（缺一即静默走错口径）----
                if ($expectDateParams) {
                    foreach (['start', 'end', 'page_index', 'page_size'] as $key) {
                        if (! array_key_exists($key, $form)) {
                            // 缺参数时返回「全量历史」形态：与上游静默行为一致，
                            // 让缺参数的实现拿到一份明显不对的数据，而不是悄悄通过。
                            return Http::response([
                                'errno' => '0',
                                'data' => ['count' => 646, 'total_num' => 5619, 'course' => []],
                            ]);
                        }
                    }
                }

                $venueId = (string) ($form['venue_id'] ?? '');

                return Http::response([
                    'errno' => '0',
                    'data' => [
                        'count' => count($rowsByVenueId[$venueId] ?? []),
                        'total_num' => count($rowsByVenueId[$venueId] ?? []),
                        'course' => $rowsByVenueId[$venueId] ?? [],
                    ],
                ]);
            },
        ]);
    }

    /**
     * 冻结两源夹具（**内联在测试文件里**，不额外引入夹具文件）。
     *
     * 数值逐字取自 captain 只读侦察冻结样本
     * `/Users/ttt/yimai-master-data/hours_two_source_compare_202609.json`
     * （2026-09-01 ~ 2026-09-24，同窗口）。该目录含 PII，**严禁复制进仓库**，
     * 因此这里只保留核对所需的聚合与逐人节数，并把真实老师显示名换成
     * 「老师NN」全局唯一假名（映射不入库）。
     *
     * 每行 = `姓名 => [课时记录节数, 预约记录课次, 课型分布]`；
     * 课型分布形如 `'g4,p1,s5'`（g=团课 / p=私教 / s=小班，后跟节数），
     * 用来把「课时记录」还原成上游 `data.course[]` 的**多行**形态
     * （一个老师多行，每行 count = 该 (老师,课程) 的上课次数，合计 = 该老师节数）。
     *
     * 两条不变量（{@see self::assertFixtureInvariants()} 断言，防止有人「顺手改数」）：
     *   1) sum(kinds) === 课时记录节数；
     *   2) 预约记录课次 <= 课时记录节数（实测方向单一：课时记录恒 ≥ 预约记录）。
     *
     * @return array<string,array{venueId:string,teachers:array<string,array{0:int,1:int,2:string}>}>
     */
    private function fixture(): array
    {
        return [
            // 绿地店：课时记录 420 节 / 预约记录 401 课次（差 +19）/ 老师 25 人
            '绿地店' => ['venueId' => '1', 'teachers' => $this->greenFixture()],
            // 东部店：课时记录 413 节 / 预约记录 344 课次（差 +69）/ 老师 25 人
            '东部店' => ['venueId' => '4250', 'teachers' => $this->eastFixture()],
        ];
    }

    /** @return array<string,array{0:int,1:int,2:string}> */
    private function greenFixture(): array
    {
        return [
            '老师01' => [10, 10, 'g4,p1,s5'],
            '老师02' => [5, 5, 'g3,p2'],
            '老师03' => [2, 1, 'p2'],
            '老师04' => [63, 62, 'g1,p41,s21'],
            '老师06' => [2, 2, 's2'],
            '老师09' => [10, 10, 'g3,p4,s3'],
            '老师11' => [15, 15, 'p15'],
            '老师13' => [1, 1, 'g1'],
            '老师14' => [30, 30, 'g6,p20,s4'],
            '老师15' => [11, 11, 'g6,p2,s3'],
            '老师16' => [5, 5, 'g2,s3'],
            '老师17' => [5, 3, 'p5'],
            '老师18' => [6, 6, 'g6'],
            '老师19' => [10, 10, 'g7,s3'],
            '老师20' => [7, 7, 'g5,p1,s1'],
            '老师22' => [6, 6, 'g3,s3'],
            '老师24' => [6, 6, 'g3,s3'],
            '老师25' => [14, 14, 'g14'],
            '老师27' => [4, 3, 'p2,s2'],
            '老师28' => [4, 4, 'g4'],
            '老师31' => [1, 1, 'g1'],
            '老师32' => [100, 92, 'g9,p80,s11'],
            '老师33' => [4, 3, 'p4'],
            '老师34' => [1, 1, 's1'],
            '老师35' => [98, 93, 'g10,p69,s19'],
        ];
    }

    /** @return array<string,array{0:int,1:int,2:string}> */
    private function eastFixture(): array
    {
        return [
            '老师01' => [17, 16, 'p12,s5'],
            '老师02' => [1, 1, 'p1'],
            '老师03' => [4, 4, 's4'],
            '老师04' => [8, 8, 'p2,s6'],
            '老师05' => [1, 1, 'g1'],
            '老师07' => [8, 8, 's8'],
            '老师08' => [38, 25, 'p16,s22'],
            '老师09' => [17, 16, 'p13,s4'],
            '老师10' => [1, 1, 's1'],
            '老师12' => [5, 5, 's5'],
            '老师14' => [1, 1, 'g1'],
            '老师15' => [4, 4, 's4'],
            '老师16' => [10, 8, 's10'],
            '老师19' => [17, 16, 'p9,s8'],
            '老师21' => [60, 38, 'p55,s5'],
            '老师23' => [61, 52, 'g1,p57,s3'],
            '老师24' => [47, 40, 'g3,p27,s17'],
            '老师26' => [35, 24, 'p24,s11'],
            '老师27' => [4, 4, 'p2,s2'],
            '老师29' => [9, 9, 's9'],
            '老师30' => [25, 25, 'p22,s3'],
            '老师31' => [13, 13, 'g2,s11'],
            '老师32' => [6, 6, 'g3,s3'],
            '老师34' => [19, 18, 'p11,s8'],
            '老师35' => [2, 1, 'p2'],
        ];
    }

    /** 夹具自证：两条不变量 + 冻结基数（改夹具就会红） */
    private function assertFixtureInvariants(): void
    {
        $fx = $this->fixture();
        $this->assertSame(420, array_sum(array_column($fx['绿地店']['teachers'], 0)), '绿地店课时记录冻结基数 420');
        $this->assertSame(401, array_sum(array_column($fx['绿地店']['teachers'], 1)), '绿地店预约记录冻结基数 401');
        $this->assertSame(413, array_sum(array_column($fx['东部店']['teachers'], 0)), '东部店课时记录冻结基数 413');
        $this->assertSame(344, array_sum(array_column($fx['东部店']['teachers'], 1)), '东部店预约记录冻结基数 344');
        $this->assertSame(25, count($fx['绿地店']['teachers']), '绿地店两源老师各 25 人');
        $this->assertSame(25, count($fx['东部店']['teachers']), '东部店两源老师各 25 人');

        foreach ($fx as $store => $spec) {
            foreach ($spec['teachers'] as $name => $t) {
                [$hour, $book, $kinds] = $t;
                $parts = $kinds === '' ? [] : explode(',', $kinds);
                $sum = array_sum(array_map(fn ($x) => (int) substr((string) $x, 1), $parts));
                $this->assertSame($hour, $sum, "夹具「{$store}/{$name}」课型分布之和必须等于课时记录节数");
                $this->assertLessThanOrEqual($hour, $book, "夹具「{$store}/{$name}」预约课次不得大于课时记录节数");
            }
        }
    }

    /**
     * 把夹具里的「课时记录」一侧展开成上游 `data.course[]` 行。
     *
     * 每个老师的 `kinds` 分布还原成多条 (老师,课程) 行 —— 正是上游的真实形态：
     * 一个老师有多行，每行的 `count` 是该 (老师,课程) 的上课次数，合计 = 该老师节数。
     *
     * @return array<int,array<string,mixed>>
     */
    private function courseRowsFromFixture(array $store): array
    {
        $out = [];
        foreach ($store['teachers'] as $name => [$hour, $book, $kinds]) {
            foreach ($kinds === '' ? [] : explode(',', $kinds) as $part) {
                $kind = ['g' => 'group', 'p' => 'private', 's' => 'small'][$part[0]] ?? 'group';
                $count = (int) substr($part, 1);
                $out[] = [
                    'coach_name' => $name,
                    'course_name' => ['private' => 'VIP定制私教｜60Min', 'small' => '精品小班', 'group' => '精品团课'][$kind],
                    'course_type' => (string) ['group' => 1, 'private' => 2, 'small' => 3][$kind],
                    'count' => $count,
                    'course_fee' => $count * 100,
                    'start_time' => '2026-09-05 10:00:00',
                ];
            }
        }

        return $out;
    }

    /**
     * 把夹具里的「预约记录」一侧灌进 `ky_bookings`（模拟同步侧已落库的事实）。
     *
     * 每个老师的 `book` 课次展开成 N 个**不同时刻**的课次；课型按该老师的课时记录分布
     * 摊平（保证 `course_kind` 三分正确、去重键不误合）。
     */
    private function seedBookingsFromFixture(array $store): void
    {
        $seq = 0;
        foreach ($store['teachers'] as $name => [$hour, $book, $kindSpec]) {
            $kinds = [];
            foreach ($kindSpec === '' ? [] : explode(',', $kindSpec) as $part) {
                $kind = ['g' => 'group', 'p' => 'private', 's' => 'small'][$part[0]] ?? 'group';
                for ($i = 0; $i < (int) substr($part, 1); $i++) {
                    $kinds[] = $kind;
                }
            }
            for ($i = 0; $i < $book; $i++) {
                $kind = $kinds[$i % max(1, count($kinds))] ?? 'group';
                $seq++;
                // 每个课次只造 1 行（去重后课次 = 行数；多会员的情形另有专门用例覆盖）
                KyBooking::create([
                    'source_key' => 'fx:'.$store['venueId'].':'.$seq,
                    'venue' => array_search($store['venueId'], ['绿地店' => '1', '东部店' => '4250'], true) ?: '绿地店',
                    'booking_type' => $kind === 'private' ? '私教' : '团课',
                    'course_kind' => $kind,
                    'member_id' => 'm'.$seq,
                    'member_name' => '会员'.$seq,
                    'phone' => '1380000'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
                    // 每个课次用不同分钟，保证去重键（venue,teacher,start_at,kind）不误合
                    'start_at' => sprintf('2026-09-%02d %02d:%02d:00', intdiv($i, 24) % 28 + 1, $i % 24, $seq % 60),
                    'course_name' => $kind === 'private' ? 'VIP定制私教｜60Min' : ($kind === 'small' ? '精品小班' : '精品团课'),
                    'teacher_name' => $name,
                    'status_raw' => '已签到',
                    'status' => 'signed',
                    'is_trial' => false,
                    'raw' => ['demo' => true],
                ]);
            }
        }
    }

    /**
     * 【核心】两源并列下发 + 冻结基数断言。
     *
     * captain 实测（2026-09-01 ~ 09-24，同窗口）：
     *   绿地店 课时记录 420 节 / 预约 401 课次（差 +19）；东部店 413 / 344（差 +69）。
     * 两源老师集合各 25 人（完全相同）—— 差异是同一批人的节数。
     *
     * 夹具 `tests/fixtures/hours_two_source_202609.json` **已脱敏**：真实老师显示名换成
     * 「老师NN」全局唯一假名（映射不入库），只保留节数/课型分布。含 PII 的那份冻结样本
     * 在仓库外（`/Users/ttt/yimai-master-data/`），**严禁**复制进仓库。
     */
    public function test_两源并列下发且差额恒等于两者之差(): void
    {
        Sanctum::actingAs($this->super());
        $fx = $this->fixture();

        $green = $fx['绿地店'];
        $east = $fx['东部店'];
        // 夹具自证：基数与 captain 冻结值一致（改夹具即红）
        $this->assertFixtureInvariants();

        // 每个老师都建一份档案（两侧都只有这些名字；夹具已脱敏）
        foreach ([$green, $east] as $store) {
            foreach ($store['teachers'] as $name => $t) {
                // 建一次即可：姓名在全表唯一（主档口径），跨店老师只建一份
                if (! PayrollProfile::where('name', $name)->exists()) {
                    $this->teacher($name, $store['venueId'] === '4250' ? '东部店' : '绿地店');
                }
            }
        }

        $this->fakeCourseRecord([
            '1' => $this->courseRowsFromFixture($green),
            '4250' => $this->courseRowsFromFixture($east),
        ]);
        $this->seedBookingsFromFixture($green);
        $this->seedBookingsFromFixture($east);

        $data = $this->getJson('/api/payroll/hours?month=2026-09')->assertOk()->json('data');

        $course = $data['bySource']['courseRecord'];
        $booking = $data['bySource']['bookingRecord'];
        $diff = $data['bySource']['diff'];

        // ---- 两源并列：两侧都在，都带节数与人数 ----
        $this->assertTrue($course['available'], '课时记录源必须可用（Http::fake 已拦截）');
        $this->assertSame(KyCourseRecordService::PATH, $course['source']);
        $this->assertSame(833, $course['sessions'], '课时记录合计 420 + 413');
        $this->assertSame(745, $booking['sessions'], '预约记录合计 401 + 344');
        // 人数：合并视图是两店的**并集** 35 人（15 位老师两店都上过课）；
        // 「每店各 25 人」在下面的分店断言里逐店验证。
        $this->assertSame(35, $course['teachers'], '合并视图：两店并集 35 位老师');
        $this->assertSame(35, $booking['teachers'], '合并视图：两源并集人数必须相同');

        // ---- 差额恒等于两者之差（总额与分店两级都要成立）----
        $this->assertSame(
            $course['sessions'] - $booking['sessions'],
            $diff['sessions'],
            '差额必须恒等于「课时记录 - 预约记录」'
        );
        $this->assertSame(88, $diff['sessions'], '833 - 745 = 88');

        // ---- 分店冻结基数 ----
        $this->assertSame(420, $diff['byVenue']['绿地店']['courseRecordSessions']);
        $this->assertSame(401, $diff['byVenue']['绿地店']['bookingSessions']);
        $this->assertSame(19, $diff['byVenue']['绿地店']['diff'], '绿地店 +19');
        $this->assertSame(25, $diff['byVenue']['绿地店']['courseRecordTeachers']);
        $this->assertSame(25, $diff['byVenue']['绿地店']['bookingTeachers']);

        $this->assertSame(413, $diff['byVenue']['东部店']['courseRecordSessions']);
        $this->assertSame(344, $diff['byVenue']['东部店']['bookingSessions']);
        $this->assertSame(69, $diff['byVenue']['东部店']['diff'], '东部店 +69');
        $this->assertSame(25, $diff['byVenue']['东部店']['courseRecordTeachers']);
        $this->assertSame(25, $diff['byVenue']['东部店']['bookingTeachers']);

        // ---- 逐人差额：恒等于该人两源之差，且方向/排序显式 ----
        $this->assertNotEmpty($diff['teachers'], '必须给出逐人差额（谁多、谁少、差几节）');
        foreach ($diff['teachers'] as $t) {
            $this->assertSame(
                $t['courseRecordSessions'] - $t['bookingSessions'],
                $t['diff'],
                "「{$t['name']}」的 diff 必须恒等于两源之差"
            );
            $this->assertContains($t['leader'], ['course', 'booking', 'equal']);
        }
        // 差额榜按绝对值降序：第一名是最该被核对的人
        $abs = array_map(fn ($t) => abs($t['diff']), $diff['teachers']);
        $sorted = $abs;
        rsort($sorted);
        $this->assertSame($sorted, $abs, '差额榜必须按 |差额| 降序（谁差得最多排最前）');
        // 实测最大差：东部店 +22（夹具已脱敏，真实姓名换成「老师NN」假名）
        $this->assertSame(22, $diff['teachers'][0]['diff'], '最大差额应为东部店 +22');
        $this->assertSame('course', $diff['teachers'][0]['leader'], '课时记录多 → leader=course');
        $this->assertSame('老师21', $diff['teachers'][0]['name']);
        $this->assertSame(60, $diff['teachers'][0]['courseRecordSessions']);
        $this->assertSame(38, $diff['teachers'][0]['bookingSessions']);

        // ---- 谁多谁少：整店 leader 与 label ----
        $this->assertSame('course', $diff['byVenue']['东部店']['leader']);
        $this->assertStringContainsString('课时记录多', $diff['label']);

        // ---- 计价口径显式声明（不得静默取其一）----
        $this->assertTrue($booking['isPricingSource'], '课时费按预约记录计价');
        $this->assertFalse($course['isPricingSource']);
        $this->assertSame('bookingRecord', $data['meta']['sources']['pricingSource']);
        $this->assertNotEmpty($data['meta']['sources']['pricingSourceReason']);
        $this->assertNotEmpty($diff['directionNote'], '必须写明为什么方向单一');

        // ---- 差异必须进 warnings（界面顶部的可见入口）----
        $w = collect($data['warnings'])->firstWhere('code', 'HOURS_SOURCE_DIFF');
        $this->assertNotNull($w, '两源有差额必须给 HOURS_SOURCE_DIFF 警告');
        $this->assertSame(88, $w['count']);
        $this->assertNotEmpty($w['diffTeachers'], '警告里必须带逐人差额明细');
    }

    /**
     * 【核心】日期参数陷阱回归：日期参数名必须是 `start` / `end`。
     *
     * captain 实测（2026-09-24）：
     *  - 传 `s_date`/`e_date`、`start_date`/`end_date`、`begin_date`、`time_start`…
     *    接口**不报错**，静默返回**全量历史**（count=646 / total_num=5619，与完全不传日期相同）；
     *  - 只有 `start`/`end` 生效（'2026-09-23' 与 '20260923' 两种形态等价）。
     *
     * 这条用例把「必须传 start/end」钉死在断言里，防止后人改回错误参数名后
     * **测试全绿但线上课时费失真**（静默失效是最危险的一类）。
     */
    public function test_日期参数必须用start_end否则上游静默返回全量历史(): void
    {
        Sanctum::actingAs($this->super());
        $this->teacher('张情', '绿地店');
        config([
            'services.ky.phone' => '13800000000',
            'services.ky.password' => 'test-password',
        ]);

        /** 上游真实行为：认 start/end，不认其他任何名字 */
        $respond = function (array $form) {
            $hasProperDates = isset($form['start']) && isset($form['end']);
            if (! $hasProperDates) {
                // 静默返回全量历史（6.7 倍于单月），且**不报错**
                return ['errno' => '0', 'data' => [
                    'count' => 646, 'total_num' => 5619,
                    'course' => [['coach_name' => '张情', 'course_name' => '全量历史课', 'count' => 5619, 'course_fee' => 561900]],
                ]];
            }

            return ['errno' => '0', 'data' => [
                'count' => 1, 'total_num' => 1,
                'course' => [['coach_name' => '张情', 'course_name' => 'VIP定制私教｜60Min', 'count' => 30, 'course_fee' => 3000]],
            ]];
        };

        Http::fake([
            KyClient::BASE.'/passport/api/login' => Http::response(['errno' => '0', 'data' => ['access_token' => 't']]),
            KyClient::BASE.'/course/api/getcoursesummaryrecordstat' => fn (Request $r) => Http::response($respond($r->data())),
        ]);

        $this->getJson('/api/payroll/hours?month=2026-09')->assertOk();

        // ---- 断言 1：发出的请求里日期参数名**只能是** start / end ----
        // 两店各发一次请求（venue 未指定 = 两店合并）
        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), 'getcoursesummaryrecordstat')) {
                return false;
            }
            $form = $r->data();

            $this->assertArrayHasKey('start', $form, '必须传 start（s_date/e_date/start_date/end_date 都会被上游静默忽略）');
            $this->assertArrayHasKey('end', $form, '必须传 end');
            // 反断言：这些「看起来很像」的名字一旦出现，说明有人改回了错误参数
            foreach (['s_date', 'e_date', 'start_date', 'end_date', 'begin_date', 'time_start', 'begin_time'] as $bad) {
                $this->assertArrayNotHasKey($bad, $form, "不得使用 {$bad}：上游会静默返回全量历史");
            }
            // 形态：YYYY-MM-DD 或 YYYYMMDD
            foreach (['start', 'end'] as $key) {
                $this->assertMatchesRegularExpression(
                    '/^\d{4}-?\d{2}-?\d{2}$/',
                    (string) $form[$key],
                    "{$key} 必须是 YYYY-MM-DD 或 YYYYMMDD 形态"
                );
            }
            $this->assertSame('2026-09-01', (string) $form['start'], 'start 必须是本月 1 号');
            $this->assertSame('2026-09-30', (string) $form['end'], 'end 必须是本月最后一天');

            return true;
        });

        // ---- 断言 2：参数名错了会拿到全量历史 → 用「结果」反证 ----
        // 若实现漏传 start/end，上面 fake 返回的 count=5619 会让课时记录侧变成 5619，
        // 这条断言就会红。所以它是「参数名正确」的**行为级**证据。
        // 两店各 30 节（同一份 fake 对两个 venue_id 都返回 30）⇒ 合计 60。
        $data = $this->getJson('/api/payroll/hours?month=2026-09')->json('data');
        $this->assertSame(60, $data['bySource']['courseRecord']['sessions'], '课时记录节数必须是本月窗口的 30×2，不是全量历史 5619');
        $this->assertNotSame(5619, $data['bySource']['courseRecord']['sessions'], '拿到全量历史说明日期参数名写错了');
    }

    /**
     * 回归：`s_date`/`e_date`/`start_date`/`end_date` 会拿到**全量历史**（与不传日期相同）。
     *
     * 这是陷阱的**直接证据**（不是推论）：本用例把错误参数名喂给同一个 fake，
     * 断言它返回的正是 captain 实测的 `count=646 / total_num=5619`，
     * 且与「完全不传日期」的响应**逐字相同** —— 证明错误参数名不会报错、只会静默走错口径。
     */
    public function test_错误日期参数名会静默返回全量历史(): void
    {
        config([
            'services.ky.phone' => '13800000000',
            'services.ky.password' => 'test-password',
        ]);

        $fullHistory = ['errno' => '0', 'data' => ['count' => 646, 'total_num' => 5619, 'total' => 646, 'course' => []]];
        Http::fake([
            KyClient::BASE.'/passport/api/login' => Http::response(['errno' => '0', 'data' => ['access_token' => 't']]),
            KyClient::BASE.'/course/api/getcoursesummaryrecordstat' => function (Request $r) use ($fullHistory) {
                $form = $r->data();
                // 只有 start/end 同时到位才算「日期生效」
                $effective = isset($form['start']) && isset($form['end']);

                return Http::response($effective
                    ? ['errno' => '0', 'data' => ['count' => 95, 'total_num' => 95, 'course' => []]]
                    : $fullHistory);
            },
        ]);

        $service = new \App\Services\KyCourseRecordService;
        $start = \Illuminate\Support\Carbon::parse('2026-09-01');
        $end = \Illuminate\Support\Carbon::parse('2026-09-24');

        // ---- 正确参数名：拿到本窗口（95）----
        $form = ['venue_id' => '4250'];
        $resp = KyClient::call(\App\Services\KyCourseRecordService::PATH, $form + [
            \App\Services\KyCourseRecordService::DATE_KEY_START => $start->toDateString(),
            \App\Services\KyCourseRecordService::DATE_KEY_END => $end->toDateString(),
            \App\Services\KyCourseRecordService::PAGE_KEY_INDEX => 1,
            \App\Services\KyCourseRecordService::PAGE_KEY_SIZE => \App\Services\KyCourseRecordService::PAGE_SIZE,
        ]);
        $this->assertSame(95, $resp['data']['count'], 'start/end 生效时应拿到本窗口数据');

        // ---- 错误参数名：静默返回全量历史，且**不报错**（errno=0）----
        foreach (['s_date', 'e_date'] as $bad) {
            $wrong = KyClient::call(\App\Services\KyCourseRecordService::PATH, $form + [
                $bad => $start->format('Ymd'),
                $bad === 's_date' ? 'e_date' : 'end_date' => $end->format('Ymd'),
            ]);
            $this->assertSame('0', (string) $wrong['errno'], "传 {$bad} 时上游**不报错**");
            $this->assertSame(646, $wrong['data']['count'], "传 {$bad} 时静默返回全量历史");
            $this->assertSame(5619, $wrong['data']['total_num']);
        }
        foreach (['start_date', 'end_date'] as $i => $bad) {
            $wrong = KyClient::call(\App\Services\KyCourseRecordService::PATH, $form + [
                'start_date' => $start->format('Ymd'),
                'end_date' => $end->format('Ymd'),
            ]);
            $this->assertSame(646, $wrong['data']['count'], "传 {$bad} 时静默返回全量历史");
        }

        // ---- 完全不传日期：与错误参数名**逐字相同** ----
        $none = KyClient::call(\App\Services\KyCourseRecordService::PATH, $form);
        $this->assertSame($fullHistory['data'], $none['data'], '错误参数名的响应与完全不传日期逐字相同');
    }

    /** 分页参数：默认只有 15 行，必须显式传 page_index + page_size（且 ≥ 2000） */
    public function test_分页参数page_index与page_size必须齐传(): void
    {
        Sanctum::actingAs($this->super());
        $this->teacher('张情', '绿地店');
        config([
            'services.ky.phone' => '13800000000',
            'services.ky.password' => 'test-password',
        ]);

        Http::fake([
            KyClient::BASE.'/passport/api/login' => Http::response(['errno' => '0', 'data' => ['access_token' => 't']]),
            KyClient::BASE.'/course/api/getcoursesummaryrecordstat' => Http::response([
                'errno' => '0',
                'data' => ['count' => 1, 'total_num' => 1, 'course' => [
                    ['coach_name' => '张情', 'course_name' => 'VIP定制私教｜60Min', 'count' => 3, 'course_fee' => 300],
                ]],
            ]),
        ]);

        $this->getJson('/api/payroll/hours?month=2026-09')->assertOk();

        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), 'getcoursesummaryrecordstat')) {
                return false;
            }
            $form = $r->data();
            $this->assertSame('1', (string) $form['page_index'], '必须显式传 page_index（默认只返回 15 行）');
            $this->assertGreaterThanOrEqual(2000, (int) $form['page_size'], 'page_size 必须显式给出且 ≥ 2000');
            $this->assertSame(\App\Services\KyCourseRecordService::PAGE_SIZE, (int) $form['page_size']);

            return true;
        });
    }

    /**
     * 【核心】「预约数为 0 = 未开课 = 不计课时」—— 走**显式分支**，不是「碰巧没有行」。
     *
     * 构造「课时记录有 1 节、预约表 0 行」的课：
     *  - 断言它**不计入课时费**（totalHours = 0 → 计价侧没有任何变化）；
     *  - 断言它出现在 `bySource.excluded.notOpened` 里，且带原因；
     *  - 断言 `HOURS_EXCLUDED_NOT_OPENED` 警告存在。
     *
     * 为什么不能只断言 totalHours = 0：那与「碰巧没有行」的结果**完全一样**，无法区分。
     * 所以本用例的判别力全部落在 excluded 清单上 —— 它只在代码**真的**表达了
     * 「这里有节次但我判定它未开课」时才出现。
     *
     * 反证（同一条用例内）：把同一节课的预约行补上 → excluded 立刻清空、节数计入，
     * 证明该分支是被「预约 0 行」这一条件驱动的，而不是恒真/恒假。
     */
    public function test_预约数为0视为未开课且走显式排除分支(): void
    {
        Sanctum::actingAs($this->super());
        $this->teacher('张情', '绿地店');
        config([
            'services.ky.phone' => '13800000000',
            'services.ky.password' => 'test-password',
        ]);

        $fake = fn () => Http::fake([
            KyClient::BASE.'/passport/api/login' => Http::response(['errno' => '0', 'data' => ['access_token' => 't']]),
            KyClient::BASE.'/course/api/getcoursesummaryrecordstat' => fn (Request $r) => Http::response(
                (string) ($r->data()['venue_id'] ?? '') === '1'
                    ? ['errno' => '0', 'data' => ['count' => 1, 'total_num' => 1, 'course' => [
                        // 课时记录：张情在绿地店 1 节
                        ['coach_name' => '张情', 'course_name' => 'VIP定制私教｜60Min', 'count' => 1, 'course_fee' => 160],
                    ]]]
                    // 东部店：无课时记录（venue 过滤后各自独立，不得互相污染）
                    : ['errno' => '0', 'data' => ['count' => 0, 'total_num' => 0, 'course' => []]]
            ),
        ]);
        $fake();

        // 预约表：**0 行**（整节课无人预约 —— 那种课在 ky_bookings 里根本没有行）
        $this->assertSame(0, KyBooking::where('teacher_name', '张情')->count());

        $data = $this->getJson('/api/payroll/hours?month=2026-09')->assertOk()->json('data');

        // ---- 1. 不计入课时费 ----
        $row = collect($data['rows'])->firstWhere('name', '张情');
        $this->assertNotNull($row, '这类老师必须仍出现在 rows 里（否则界面看不到，等于静默丢弃）');
        $this->assertSame(0, $row['totalHours'], '预约 0 行 → 不计课时');
        $this->assertSame(0, $row['classCount']);
        $this->assertSame(1, $row['courseRecordSessions'], '课时记录侧仍如实回显 1 节，供核对');
        // 符号约定与全局一致：sourceDiff = 课时记录 - 预约记录（正数 = 课时记录多）
        $this->assertSame(1, $row['sourceDiff'], '差额 +1：课时记录有 1 节而预约记录 0 节');
        $this->assertSame('course', $row['sourceLeader']);

        // ---- 2. 走**显式分支**：excluded 清单带原因 ----
        $excluded = $data['bySource']['excluded'];
        $this->assertSame(1, $excluded['notOpenedCount'], '必须有 1 条「未开课」显式排除记录');
        $this->assertSame('张情', $excluded['notOpened'][0]['name']);
        $this->assertSame(1, $excluded['notOpened'][0]['courseRecordSessions']);
        $this->assertSame(0, $excluded['notOpened'][0]['bookingSessions']);
        $this->assertStringContainsString('未开课', $excluded['notOpened'][0]['reason']);
        $this->assertStringContainsString('不计入课时费', $excluded['notOpened'][0]['reason']);
        $this->assertNotEmpty($excluded['rule'], '必须回显这条口径本身');

        $w = collect($data['warnings'])->firstWhere('code', 'HOURS_EXCLUDED_NOT_OPENED');
        $this->assertNotNull($w, '未开课排除必须给 HOURS_EXCLUDED_NOT_OPENED 警告（界面可见）');
        $this->assertSame(1, $w['count']);
        $this->assertContains('张情', $w['names']);

        // ---- 3. 反证：补上预约行 → excluded 清空、节数计入 ----
        $this->booking('绿地店', '张情', '2026-09-05 10:00:00', 'private', ['course_name' => 'VIP定制私教｜60Min']);
        $after = $this->getJson('/api/payroll/hours?month=2026-09')->assertOk()->json('data');

        $this->assertSame(0, $after['bySource']['excluded']['notOpenedCount'], '有预约后不再是「未开课」');
        $this->assertNull(
            collect($after['warnings'])->firstWhere('code', 'HOURS_EXCLUDED_NOT_OPENED'),
            '不应再出现未开课警告'
        );
        $rowAfter = collect($after['rows'])->firstWhere('name', '张情');
        $this->assertSame(1, $rowAfter['totalHours'], '有预约的课次正常计入');
        $this->assertSame(0, $rowAfter['sourceDiff'], '两源一致 → 差额 0');
        $this->assertSame('equal', $rowAfter['sourceLeader']);
        $this->assertNull(
            collect($after['warnings'])->firstWhere('code', 'HOURS_SOURCE_DIFF'),
            '两源一致时不应报差额'
        );
    }

    /** 课时记录源不可用（凭据缺失/上游失败）时**显式降级**，不阻断、不静默 */
    public function test_课时记录源不可用时显式降级不阻断(): void
    {
        Sanctum::actingAs($this->super());
        $this->teacher('张情', '绿地店');
        $this->booking('绿地店', '张情', '2026-09-05 10:00:00', 'private');

        // 凭据缺失 → 不发请求，直接显式不可用（本地/CI 常态，不得变成真实网络调用）
        config(['services.ky.phone' => '', 'services.ky.password' => '']);
        Http::fake();   // 任何真实 HTTP 调用都会拿到空 200 响应，从而暴露问题

        $data = $this->getJson('/api/payroll/hours?month=2026-09')->assertOk()->json('data');

        $this->assertFalse($data['bySource']['courseRecord']['available']);
        $this->assertStringContainsString('凭据未配置', (string) $data['bySource']['courseRecord']['error']);
        $this->assertTrue($data['bySource']['bookingRecord']['available'], '第一源仍必须可用');
        // 第一源的数据必须完好（不能因为第二源不可用就把整页弄没）
        $row = collect($data['rows'])->firstWhere('name', '张情');
        $this->assertSame(1, $row['totalHours']);

        $w = collect($data['warnings'])->firstWhere('code', 'COURSE_RECORD_UNAVAILABLE');
        $this->assertNotNull($w, '第二源不可用必须显式告知，不得静默降级成单源');
        $this->assertStringContainsString('只有预约记录一侧', $w['message']);
    }

    /** 上游报错（HTTP 500）时同样是显式不可用，而不是把课时页弄成 500 */
    public function test_课时记录上游报错时降级不影响第一源(): void
    {
        Sanctum::actingAs($this->super());
        $this->teacher('张情', '绿地店');
        $this->booking('绿地店', '张情', '2026-09-05 10:00:00', 'private');
        config([
            'services.ky.phone' => '13800000000',
            'services.ky.password' => 'test-password',
        ]);

        Http::fake([
            KyClient::BASE.'/passport/api/login' => Http::response(['errno' => '0', 'data' => ['access_token' => 't']]),
            KyClient::BASE.'/course/api/getcoursesummaryrecordstat' => Http::response(['error' => 'boom'], 500),
        ]);

        $data = $this->getJson('/api/payroll/hours?month=2026-09')->assertOk()->json('data');
        $this->assertFalse($data['bySource']['courseRecord']['available']);
        $this->assertNotEmpty($data['bySource']['courseRecord']['error']);
        $this->assertSame(1, collect($data['rows'])->firstWhere('name', '张情')['totalHours']);
    }

    /** 第二源有、第一源无的老师**不被静默丢弃**：单独成行并计入差额榜 */
    public function test_课时记录独有的老师不静默丢弃(): void
    {
        Sanctum::actingAs($this->super());
        $this->teacher('张情', '绿地店');
        config([
            'services.ky.phone' => '13800000000',
            'services.ky.password' => 'test-password',
        ]);

        Http::fake([
            KyClient::BASE.'/passport/api/login' => Http::response(['errno' => '0', 'data' => ['access_token' => 't']]),
            KyClient::BASE.'/course/api/getcoursesummaryrecordstat' => fn (Request $r) => Http::response(
                (string) ($r->data()['venue_id'] ?? '') === '1'
                    ? ['errno' => '0', 'data' => ['count' => 1, 'total_num' => 1, 'course' => [
                        ['coach_name' => '仅课时记录有此人', 'course_name' => '精品团课', 'count' => 2, 'course_fee' => 320],
                    ]]]
                    : ['errno' => '0', 'data' => ['count' => 0, 'total_num' => 0, 'course' => []]]
            ),
        ]);

        $data = $this->getJson('/api/payroll/hours?month=2026-09')->assertOk()->json('data');

        $row = collect($data['rows'])->firstWhere('name', '仅课时记录有此人');
        $this->assertNotNull($row, '只在课时记录里出现的老师必须仍出现在 rows（否则就是静默丢弃）');
        $this->assertSame(2, $row['courseRecordSessions']);
        $this->assertSame(0, $row['classCount']);
        // 对不上档案 → 进 TEACHER_UNRESOLVED，不静默
        $w = collect($data['warnings'])->firstWhere('code', 'TEACHER_UNRESOLVED');
        $this->assertContains('仅课时记录有此人', $w['names']);
    }

    /** 四个参数齐传：缺任何一个都会拿到「全量历史」形态，用响应值反证（参数级 + 行为级双证据） */
    public function test_四个参数齐传缺一即静默走错口径(): void
    {
        Sanctum::actingAs($this->super());
        $this->teacher('张情', '绿地店');
        $this->fakeCourseRecord(['1' => [
            ['coach_name' => '张情', 'course_name' => 'VIP定制私教｜60Min', 'count' => 7, 'course_fee' => 1120],
        ]]);

        $data = $this->getJson('/api/payroll/hours?month=2026-09')->assertOk()->json('data');
        // 四个参数都在时，拿到的是窗口内的 7 节，不是 fixture 里缺参时返回的 646
        $this->assertSame(7, $data['bySource']['courseRecord']['sessions']);

        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), 'getcoursesummaryrecordstat')) {
                return false;
            }
            foreach (['start', 'end', 'page_index', 'page_size'] as $key) {
                $this->assertArrayHasKey($key, $r->data(), "必须齐传 {$key}");
            }

            return true;
        });
    }

    /** 翻页：上游把结果分多页返回时必须取全（page_size 满页才继续） */
    public function test_课时记录多页时必须取全(): void
    {
        $service = new \App\Services\KyCourseRecordService;
        config([
            'services.ky.phone' => '13800000000',
            'services.ky.password' => 'test-password',
        ]);

        // 第一页返回满页（page_size 条），第二页返回少量 → 应共 2 次请求
        $full = [];
        for ($i = 0; $i < \App\Services\KyCourseRecordService::PAGE_SIZE; $i++) {
            $full[] = ['coach_name' => '老师'.($i % 3), 'course_name' => '课'.$i, 'count' => 1, 'course_fee' => 100];
        }
        $tail = [['coach_name' => '老师X', 'course_name' => '尾页课', 'count' => 5, 'course_fee' => 500]];

        $calls = [];
        Http::fake([
            KyClient::BASE.'/passport/api/login' => Http::response(['errno' => '0', 'data' => ['access_token' => 't']]),
            KyClient::BASE.'/course/api/getcoursesummaryrecordstat' => function (Request $r) use ($full, $tail, &$calls) {
                $page = (int) ($r->data()['page_index'] ?? 0);
                $calls[] = $page;

                return Http::response(['errno' => '0', 'data' => [
                    'count' => 1, 'total_num' => 1, 'course' => $page === 1 ? $full : $tail,
                ]]);
            },
        ]);

        $res = $service->fetchVenue('1', \Illuminate\Support\Carbon::parse('2026-09-01'), \Illuminate\Support\Carbon::parse('2026-09-30'));

        $this->assertSame([1, 2], $calls, '第一页满页时必须翻第二页');
        $this->assertSame(\App\Services\KyCourseRecordService::PAGE_SIZE + 5, $res['sessions'], '必须取全两页');
        $this->assertSame(4, $res['teachers'], '老师 X + 老师0/1/2');
    }
}
