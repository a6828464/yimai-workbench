<?php

namespace Tests\Feature;

use App\Models\KyBooking;
use App\Models\PayrollProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
 */
class PayrollHoursTest extends TestCase
{
    use RefreshDatabase;

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
}
