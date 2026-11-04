<?php

namespace Tests\Feature;

use App\Http\Controllers\SystemController;
use App\Models\Customer;
use App\Models\KyBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * v3.11 课后分析候选人「私教 / 小班+团课」分组 ＋ 会员卡项全量 ＋ 公开更新日志。
 *
 * 用户原话：
 *  - 「点击新建，没有显示出这个老师上过课的所有会员……需要显示最近 7 天上过课的所有会员：
 *     1. 私教作为一个类目；2. 小班和团课作为一个类目合在一起」；
 *  - 「点开会员信息时，可以显示他的主卡以外还有其余的卡项」；
 *  - 「在小程序我的页面的关于菜单里，可以显示版本号和简单基础的更新内容」。
 */
class PostClassCandidateGroupTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function makeUser(string $role, string $name, string $username, string $venue = '绿地店', array $roles = []): User
    {
        return User::factory()->create([
            'name' => $name,
            'username' => $username,
            'role' => $role,
            'roles' => $roles === [] ? null : $roles,
            'venue' => $venue,
            'venues' => $venue === '' ? [] : [$venue],
            'status' => '启用',
        ]);
    }

    /** candidates 的 URL：中文参数必须走 http_build_query，否则测试客户端的查询串不是合法 UTF-8 */
    private function candidatesUrl(array $query = []): string
    {
        $qs = http_build_query($query);

        return '/api/post-class-reviews/candidates'.($qs === '' ? '' : '?'.$qs);
    }

    /** 一条排课事实（默认：绿地店、王教练、昨天、已签到） */
    private function booking(string $kind, string $member, array $overrides = []): KyBooking
    {
        $this->seq++;
        $type = ['private' => '私教', 'small' => '精品课', 'group' => '团课'][$kind] ?? '团课';

        return KyBooking::create(array_merge([
            'source_key' => "77:{$type}:{$this->seq}",
            'venue' => '绿地店',
            'booking_type' => $type,
            'course_kind' => $kind,
            'member_id' => (string) (5000 + $this->seq),
            'member_name' => $member,
            'phone' => '139'.str_pad((string) $this->seq, 8, '0', STR_PAD_LEFT),
            'start_at' => now()->subDay(),
            'teacher_name' => '王教练',
            'status' => 'signed',
            'is_trial' => false,
        ], $overrides));
    }

    // ─────────────────────────────────────────────────────────────
    // 候选人分组
    // ─────────────────────────────────────────────────────────────

    /** 私教一组、小班＋团课合一组，两组互斥；既有字段一个都不少 */
    public function test_candidates_split_into_private_and_group_buckets(): void
    {
        $this->booking('private', '私教学员甲');
        $this->booking('small', '小班学员乙');
        $this->booking('group', '团课学员丙');
        Customer::create([
            'name' => '私教学员甲', 'phone' => '13900000001', 'venue' => '绿地店',
            'external_id' => 'ky:77:5001', 'layer' => 'P2', 'consultant' => '李顾问',
        ]);

        Sanctum::actingAs($this->makeUser('R_TEACHER', '王教练', 'coach-wang'));
        $data = $this->getJson($this->candidatesUrl())->assertOk()->json('data');

        $this->assertSame(7, $data['days'], 'days 默认必须是 7');
        $this->assertSame('all', $data['group'], 'group 默认必须是 all');
        $this->assertSame(['private' => 1, 'group' => 2], $data['groups']);

        $byName = collect($data['records'])->keyBy('studentName');
        $this->assertSame('private', $byName['私教学员甲']['group']);
        $this->assertSame('私教', $byName['私教学员甲']['groupLabel']);
        $this->assertSame('private', $byName['私教学员甲']['courseKind']);
        $this->assertSame('group', $byName['小班学员乙']['group'], '小班落进「小班+团课」组');
        $this->assertSame('group', $byName['团课学员丙']['group'], '团课落进「小班+团课」组');
        $this->assertSame('small', $byName['小班学员乙']['courseKind'], 'courseKind 保留原始课型');
        $this->assertSame('group', $byName['团课学员丙']['courseKind']);

        // 两组互斥：同一条记录只能属于一组，且分组与原始课型一致
        foreach ($data['records'] as $row) {
            $this->assertContains($row['group'], ['private', 'group']);
            $this->assertSame($row['courseKind'] === 'private' ? 'private' : 'group', $row['group']);
        }

        // 既有字段保持原样（老前端仍按这些字段渲染）
        foreach (['source', 'personType', 'bookingId', 'classAt', 'date', 'time', 'venue', 'courseName',
            'teacherName', 'studentName', 'phoneTail', 'phone', 'kind', 'isTrial', 'scene', 'status',
            'customerId', 'leadId', 'leadStatus', 'hasReview'] as $key) {
            $this->assertArrayHasKey($key, $data['records'][0]);
        }
        $this->assertSame('member', $byName['私教学员甲']['personType']);
        $this->assertSame('none', $byName['小班学员乙']['personType']);

        // 最近一节课 + 聚合节数
        $this->assertArrayHasKey('lastClassAt', $data['records'][0]);
        $this->assertArrayHasKey('lastClassTitle', $data['records'][0]);
        $this->assertSame(1, $data['records'][0]['classCount']);
    }

    /** group 参数只裁剪 records；groups 计数保持全量（否则另一组角标永远是 0） */
    public function test_group_param_scopes_records_but_keeps_full_counts(): void
    {
        $this->booking('private', '私教学员甲');
        $this->booking('small', '小班学员乙');
        $this->booking('group', '团课学员丙');

        Sanctum::actingAs($this->makeUser('R_TEACHER', '王教练', 'coach-wang'));

        $private = $this->getJson($this->candidatesUrl(['group' => 'private']))->assertOk()->json('data');
        $this->assertSame(['私教学员甲'], array_column($private['records'], 'studentName'));
        $this->assertSame(['private' => 1, 'group' => 2], $private['groups'], '切到私教组也要看到小班+团课组的人数');

        $group = $this->getJson($this->candidatesUrl(['group' => 'group']))->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['小班学员乙', '团课学员丙'], array_column($group['records'], 'studentName'));
        $this->assertSame(['private' => 1, 'group' => 2], $group['groups']);

        // 非法值回落到 all，不 500
        $bad = $this->getJson($this->candidatesUrl(['group' => 'bogus']))->assertOk()->json('data');
        $this->assertCount(3, $bad['records']);
    }

    /** 同一会员在同一组里只出一行（取最近一节课，节数累加）；两类课都上过则两组各一行 */
    public function test_same_member_is_collapsed_per_bucket(): void
    {
        $this->booking('private', '多课会员', ['start_at' => now()->subDays(3)]);
        $this->booking('private', '多课会员', ['start_at' => now()->subDay()]);
        $this->booking('group', '多课会员', ['start_at' => now()->subDays(2)]);
        Customer::create([
            'name' => '多课会员', 'phone' => '13900000001', 'venue' => '绿地店',
            'external_id' => 'ky:77:5001', 'layer' => 'P2',
        ]);
        // 会员档案按手机号匹配：把三行的手机号统一成档案手机号
        KyBooking::query()->update(['phone' => '13900000001']);

        Sanctum::actingAs($this->makeUser('R_TEACHER', '王教练', 'coach-wang'));
        $data = $this->getJson($this->candidatesUrl())->assertOk()->json('data');

        $rows = collect($data['records'])->where('studentName', '多课会员')->values();
        $this->assertCount(2, $rows, '两节私教 → 一行；一节团课 → 一行');
        $private = $rows->firstWhere('group', 'private');
        $this->assertSame(2, $private['classCount']);
        $this->assertSame(now()->subDay()->format('Y-m-d H:i'), $private['lastClassAt'], '取最近一节课');
    }

    /** 「上过课」= 实际签到；取消 / 爽约 / 只预约都不算 */
    public function test_only_attended_classes_are_candidates(): void
    {
        $this->booking('private', '已签到甲', ['status' => 'signed']);
        $this->booking('private', '已取消乙', ['status' => 'cancelled']);
        $this->booking('private', '爽约丙', ['status' => 'no_show']);
        $this->booking('private', '只预约丁', ['status' => 'booked']);

        Sanctum::actingAs($this->makeUser('R_TEACHER', '王教练', 'coach-wang'));
        $names = array_column($this->getJson($this->candidatesUrl())->json('data.records'), 'studentName');

        $this->assertSame(['已签到甲'], $names);
    }

    /** days 窗口：默认 7 天，越界夹到 1..60；超出窗口的课不进候选 */
    public function test_days_window_filters_old_classes(): void
    {
        $this->booking('private', '近期学员', ['start_at' => now()->subDays(2)]);
        $this->booking('private', '十天前学员', ['start_at' => now()->subDays(10)]);
        $this->booking('private', '三十天前学员', ['start_at' => now()->subDays(30)]);

        Sanctum::actingAs($this->makeUser('R_TEACHER', '王教练', 'coach-wang'));

        $default = $this->getJson($this->candidatesUrl())->assertOk()->json('data');
        $this->assertSame(['近期学员'], array_column($default['records'], 'studentName'));

        $wide = $this->getJson($this->candidatesUrl(['days' => 15]))->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['近期学员', '十天前学员'], array_column($wide['records'], 'studentName'));
        $this->assertSame(15, $wide['days']);

        $clamped = $this->getJson($this->candidatesUrl(['days' => 999]))->assertOk()->json('data');
        $this->assertSame(60, $clamped['days'], 'days 上限 60');

        $floor = $this->getJson($this->candidatesUrl(['days' => 0]))->assertOk()->json('data');
        $this->assertSame(1, $floor['days'], 'days 下限 1');
    }

    /** 老师只看自己的课：别的老师的学员一条都不能出现 */
    public function test_teacher_sees_only_own_students(): void
    {
        $this->booking('private', '王教练学员');
        $this->booking('private', '李教练学员', ['teacher_name' => '李教练']);
        $this->makeUser('R_TEACHER', '李教练', 'coach-li');

        Sanctum::actingAs($this->makeUser('R_TEACHER', '王教练', 'coach-wang'));
        $data = $this->getJson($this->candidatesUrl())->assertOk()->json('data');

        $this->assertSame(['王教练学员'], array_column($data['records'], 'studentName'));
        $this->assertSame(['private' => 1, 'group' => 0], $data['groups']);
    }

    /** 新媒体（非店长/超管、非老师侧）不传 teacher：不给「门店全量候选人」 */
    public function test_media_without_teacher_gets_no_store_wide_records(): void
    {
        $this->booking('private', '王教练学员');
        $this->booking('group', '李教练团课学员', ['teacher_name' => '李教练']);
        // 门店刻意填成绿地店：证明置空不是因为 venue 为空而"碰巧"查不到
        Sanctum::actingAs($this->makeUser('R_MEDIA', '小美', 'media-mei', '绿地店'));

        $data = $this->getJson($this->candidatesUrl())->assertOk()->json('data');
        $this->assertSame([], $data['records'], '门店全量候选人只对店长/超管开放');
        $this->assertSame(['private' => 0, 'group' => 0], $data['groups']);
        $this->assertSame(['private' => 0, 'group' => 0], $data['groupsPending']);
        $this->assertSame(0, $data['pendingCount']);
    }

    /** 角色识别不出来（roles 漏写 / 旧角色码）：失败关闭，返回全空而不是全店名单 */
    public function test_unknown_role_gets_empty_payload_instead_of_store_roster(): void
    {
        $this->booking('private', '王教练学员');
        Sanctum::actingAs($this->makeUser('R_LEGACY', '历史账号', 'legacy-user', '绿地店'));

        $data = $this->getJson($this->candidatesUrl())->assertOk()->json('data');
        $this->assertSame([], $data['records']);
        $this->assertSame([], $data['leads']);
        $this->assertSame([], $data['members']);
        $this->assertSame(['private' => 0, 'group' => 0], $data['groups']);
        $this->assertSame(7, $data['days'], '空结果也要带上默认参数，前端不用判空');
    }

    /** 店长不传 teacher：本店全量（多位老师的学生都在），跨店不在 */
    public function test_manager_without_teacher_sees_store_wide_records(): void
    {
        $this->booking('private', '绿地王教练学员');
        $this->booking('group', '绿地李教练学员', ['teacher_name' => '李教练']);
        $this->booking('private', '东部店学员', ['venue' => '东部店']);

        Sanctum::actingAs($this->makeUser('R_MANAGER', '钱店长', 'mgr-qian'));
        $data = $this->getJson($this->candidatesUrl())->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(
            ['绿地王教练学员', '绿地李教练学员'],
            array_column($data['records'], 'studentName')
        );
        $this->assertSame(['private' => 1, 'group' => 1], $data['groups']);
    }

    // ─────────────────────────────────────────────────────────────
    // teacher 参数与越权
    // ─────────────────────────────────────────────────────────────

    /** 老师只能用本人：传他人（姓名或 id）一律 403，传自己照常 */
    public function test_teacher_cannot_ask_for_another_teachers_students(): void
    {
        $this->booking('private', '王教练学员');
        $this->booking('private', '李教练学员', ['teacher_name' => '李教练']);
        $wang = $this->makeUser('R_TEACHER', '王教练', 'coach-wang');
        $li = $this->makeUser('R_TEACHER', '李教练', 'coach-li');
        Sanctum::actingAs($wang);

        $this->getJson($this->candidatesUrl(['teacher' => '李教练']))->assertStatus(403);
        $this->getJson($this->candidatesUrl(['teacher' => $li->id]))->assertStatus(403);
        // 服务老师同属老师侧，也不能代看
        Sanctum::actingAs($this->makeUser('R_SERVICE', '张顾问', 'svc-zhang'));
        $this->getJson($this->candidatesUrl(['teacher' => '王教练']))->assertStatus(403);

        // 传自己可以：结果与不传一致
        Sanctum::actingAs($wang->fresh());
        $self = $this->getJson($this->candidatesUrl(['teacher' => '王教练']))->assertOk()->json('data');
        $this->assertSame('王教练', $self['teacher']['name']);
        $this->assertSame(['王教练学员'], array_column($self['records'], 'studentName'));
    }

    /** 店长 / 超管可以指定老师代看 */
    public function test_manager_and_super_can_proxy_a_teacher(): void
    {
        $this->booking('private', '王教练学员');
        $this->booking('group', '王教练团课学员');
        $this->booking('private', '李教练学员', ['teacher_name' => '李教练']);
        $wang = $this->makeUser('R_TEACHER', '王教练', 'coach-wang');
        $this->makeUser('R_TEACHER', '李教练', 'coach-li');

        Sanctum::actingAs($this->makeUser('R_MANAGER', '钱店长', 'mgr-qian'));
        $byName = $this->getJson($this->candidatesUrl(['teacher' => '王教练']))->assertOk()->json('data');
        $this->assertSame($wang->id, $byName['teacher']['id']);
        $this->assertEqualsCanonicalizing(['王教练学员', '王教练团课学员'], array_column($byName['records'], 'studentName'));
        $this->assertSame(['private' => 1, 'group' => 1], $byName['groups']);

        // 超管：venue 为空（双店）也能按 id 代看
        Sanctum::actingAs($this->makeUser('R_SUPER', '馆长', 'super-boss', ''));
        $byId = $this->getJson($this->candidatesUrl(['teacher' => $wang->id]))->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['王教练学员', '王教练团课学员'], array_column($byId['records'], 'studentName'));

        // 不存在的老师：422，不 500
        $this->getJson($this->candidatesUrl(['teacher' => '查无此人']))->assertStatus(422);
        $this->getJson($this->candidatesUrl(['teacher' => 999999]))->assertStatus(422);
    }

    /** 新媒体是双店账号（venue 为空）：代看某位老师时不能被门店锁压成空集 */
    public function test_media_can_proxy_teacher_across_venues(): void
    {
        $this->booking('private', '绿地店学员');
        $this->booking('group', '东部店学员', ['venue' => '东部店']);
        $this->makeUser('R_TEACHER', '王教练', 'coach-wang');   // teacher 参数必须能解析到账号

        Sanctum::actingAs($this->makeUser('R_MEDIA', '小美', 'media-mei', ''));
        $data = $this->getJson($this->candidatesUrl(['teacher' => '王教练']))->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(['绿地店学员', '东部店学员'], array_column($data['records'], 'studentName'));
    }

    /** 没有任何排课时返回空数组，不是错误 */
    public function test_empty_result_returns_empty_records(): void
    {
        Sanctum::actingAs($this->makeUser('R_TEACHER', '王教练', 'coach-wang'));
        $data = $this->getJson($this->candidatesUrl())->assertOk()->json('data');

        $this->assertSame([], $data['records']);
        $this->assertSame(['private' => 0, 'group' => 0], $data['groups']);
        $this->assertSame(0, $data['pendingCount']);
    }

    // ─────────────────────────────────────────────────────────────
    // 会员详情：全部卡项
    // ─────────────────────────────────────────────────────────────

    /** 会员详情返回三态全量卡项，同时判断层输入（customer.cardsList）保持原样 */
    public function test_customer_detail_exposes_all_cards_in_three_states(): void
    {
        $customer = Customer::create([
            'name' => '多卡会员', 'phone' => '13900008888', 'venue' => '绿地店',
            'external_id' => 'ky:77:8888', 'layer' => 'P2', 'main_card' => '私教30节',
            'cards_list' => [
                ['id' => 'c1', 'cardNo' => 'K001', 'title' => '私教30节', 'unit' => '节', 'residue' => 12,
                    'deadline' => '2027-01-01', 'state' => 'current', 'unactivated' => false, 'type' => '1'],
                ['id' => 'c2', 'cardNo' => 'K002', 'title' => '团课10次', 'unit' => '节', 'residue' => 10,
                    'deadline' => '2027-06-01', 'state' => 'unactivated', 'unactivated' => true, 'type' => '1'],
            ],
            'card_stats' => [
                'historyCards' => [
                    ['id' => 'c0', 'cardNo' => 'K000', 'title' => '体验卡3次', 'unit' => '节', 'residue' => 0,
                        'deadline' => '2026-01-01', 'state' => 'history', 'expired' => true, 'type' => '1'],
                ],
            ],
        ]);

        Sanctum::actingAs($this->makeUser('R_MANAGER', '钱店长', 'mgr-qian'));
        $data = $this->getJson('/api/customers/'.$customer->id)->assertOk()->json('data');

        $cards = collect($data['cardsList'])->keyBy('id');
        $this->assertCount(3, $cards, '在用 / 未开卡 / 已过期 三类都要在');
        $this->assertSame('在用', $cards['c1']['stateLabel']);
        $this->assertSame('未开卡', $cards['c2']['stateLabel']);
        $this->assertSame('已过期', $cards['c0']['stateLabel']);
        $this->assertTrue($cards['c1']['isMain'], '主卡打标');
        $this->assertFalse($cards['c2']['isMain']);

        $this->assertSame(['active' => 1, 'unactivated' => 1, 'expired' => 1, 'total' => 3], $data['cardGroups']);

        // 判定口径不动：customer.cardsList 仍然只有在用 + 未开卡（过期卡不进判定层）
        $rawCards = $data['customer']['cardsList'];
        $this->assertCount(2, $rawCards);
        $this->assertNotContains('体验卡3次', array_column($rawCards, 'title'));
    }

    // ─────────────────────────────────────────────────────────────
    // 公开更新日志（小程序「关于」未登录也要能看）
    // ─────────────────────────────────────────────────────────────

    /**
     * 把 base_path 指到一个临时站点根，从而「固定」CHANGELOG.md 的内容（$content=null = 文件缺失）。
     *
     * 不用子类覆写：SystemController 是 final 的；也不用真的动仓库里的 CHANGELOG.md ——
     * 那个文件是其它测试与发行包共用的输入。临时目录里父级为空，三处候选路径都不存在，
     * 「文件缺失」这一支才是确定性的。
     */
    private function fakeBaseWithChangelog(?string $content): void
    {
        $root = sys_get_temp_dir().'/yimai-changelog-'.bin2hex(random_bytes(6));
        $base = $root.'/backend';
        mkdir($base, 0777, true);
        if ($content !== null) {
            file_put_contents($base.'/CHANGELOG.md', $content);
        }
        $this->app->setBasePath($base);
    }

    /** 免登录可读；解析 `## ` 标题 → {version,title,date}；limit 默认 3、上限 10 */
    public function test_changelog_is_public_and_parses_headings(): void
    {
        $this->fakeBaseWithChangelog(implode("\n", [
            '# 更新日志',
            '',
            '## 2026-11-04 ｜ feat: v3.11.0 课后分析分组',
            '正文',
            '## 2026-10-30 ｜ fix: v3.10.2 在线更新修复',
            '正文',
            '## 2026-10-12 14:01 ｜ chore: v3.10.1 身份归一',
            '正文',
            '## 2026-09-01 ｜ docs: 无版本号的一条',
            '正文',
            '### 这是三级标题，不该被当成版本',
        ]));

        // 未登录：公开只读
        $data = $this->getJson('/api/system/changelog')->assertOk()->json('data');
        $this->assertCount(3, $data['records'], 'limit 默认 3');
        $this->assertSame([
            'version' => '3.11.0',
            'title' => 'feat: v3.11.0 课后分析分组',
            'date' => '2026-11-04',
        ], $data['records'][0]);
        $this->assertSame('3.10.2', $data['records'][1]['version']);
        $this->assertSame('2026-10-12', $data['records'][2]['date'], '早期条目带时分，date 只到天');
        $this->assertSame('', $data['latest']['version'], '免登录不去拉远端发行说明');
        // 匿名只拿标题列表：正文一律不下发（CHANGELOG 里写着内部上线细节）
        $this->assertSame('', $data['content'], '匿名调用不返回日志正文');

        $two = $this->getJson('/api/system/changelog?limit=2')->assertOk()->json('data');
        $this->assertCount(2, $two['records']);

        $ten = $this->getJson('/api/system/changelog?limit=99')->assertOk()->json('data');
        $this->assertCount(4, $ten['records'], '只有 4 条二级标题；limit 上限 10 也不会越界');

        $zero = $this->getJson('/api/system/changelog?limit=0')->assertOk()->json('data');
        $this->assertCount(3, $zero['records'], 'limit<1 回落到默认 3');

        // 对照：登录后仍然拿得到正文（管理端「版本更新」页是登录态，行为不变）
        Http::fake(['*' => Http::response([
            'tag_name' => 'v3.11.0', 'name' => 'v3.11.0', 'body' => 'notes',
            'created_at' => '2026-11-04T00:00:00Z', 'html_url' => 'https://example.test',
        ], 200)]);
        Sanctum::actingAs($this->makeUser('R_SUPER', '馆长', 'changelog-super', ''));
        $authed = $this->getJson('/api/system/changelog')->assertOk()->json('data');
        $this->assertStringContainsString('# 更新日志', $authed['content'], '登录后仍返回日志正文');
        $this->assertStringContainsString('## 2026-11-04 ｜ feat: v3.11.0 课后分析分组', $authed['content']);
        $this->assertNotEmpty($authed['records'], '登录后 records 不变');
    }

    /** 文件缺失 / 读不到：records 是空数组，HTTP 仍然 200（不是 500） */
    public function test_changelog_returns_empty_records_when_file_missing(): void
    {
        $this->fakeBaseWithChangelog(null);

        $data = $this->getJson('/api/system/changelog')->assertOk()->json('data');
        $this->assertSame([], $data['records']);
        $this->assertSame('', $data['content'], '匿名调用连兜底长文也不下发');

        // 登录后文件缺失 → 固定兜底文案，仍是 200，不是 500
        Http::fake(['*' => Http::response([], 404)]);
        Sanctum::actingAs($this->makeUser('R_SUPER', '馆长', 'changelog-super-missing', ''));
        $authed = $this->getJson('/api/system/changelog')->assertOk()->json('data');
        $this->assertSame([], $authed['records']);
        $this->assertStringContainsString('暂无可用更新日志', $authed['content']);
    }

    /** 解析器本身：空内容 / 没有二级标题 / 全角半角分隔符 */
    public function test_changelog_parser_handles_empty_and_odd_input(): void
    {
        $this->assertSame([], SystemController::parseChangelogHeadings(''));
        $this->assertSame([], SystemController::parseChangelogHeadings("# 更新日志\n\n没有二级标题\n"));
        $this->assertSame(
            [['version' => '', 'title' => 'no separator here', 'date' => '2026-11-04']],
            SystemController::parseChangelogHeadings('## 2026-11-04 no separator here')
        );
        $this->assertSame(
            [['version' => '3.9.0', 'title' => 'feat: v3.9.0 半角竖线', 'date' => '2026-10-01']],
            SystemController::parseChangelogHeadings('## 2026-10-01 | feat: v3.9.0 半角竖线')
        );
    }

    /** 登录后仍然给管理端那两块（content + latest），管理端「版本更新」页不用改 */
    public function test_changelog_keeps_admin_shape_for_logged_in_users(): void
    {
        Http::fake([
            '*' => Http::response([
                'tag_name' => 'v3.11.0', 'name' => 'v3.11.0', 'body' => 'notes',
                'created_at' => '2026-11-04T00:00:00Z', 'html_url' => 'https://example.test',
            ], 200),
        ]);
        $this->fakeBaseWithChangelog("## 2026-11-04 ｜ feat: v3.11.0 分组\n");

        Sanctum::actingAs($this->makeUser('R_SUPER', '馆长', 'super-boss', ''));
        $data = $this->getJson('/api/system/changelog')->assertOk()->json('data');

        $this->assertArrayHasKey('content', $data);
        $this->assertArrayHasKey('latest', $data);
        // 登录态必须能读到正文，否则管理端「版本更新」页会变成空白
        $this->assertStringContainsString('feat: v3.11.0 分组', $data['content']);
        $this->assertSame('v3.11.0', $data['latest']['version']);
        $this->assertSame('3.11.0', $data['records'][0]['version']);
    }
}
