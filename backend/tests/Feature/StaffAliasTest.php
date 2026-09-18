<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\StaffAlias;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 人员归属映射。
 *
 * 背景：业务表的归属列（service_teacher / owner / consultant / teacher_name / created_by）
 * 存的是**姓名字符串，不是外键**，所以「谁能看到这条数据」取决于姓名能不能对上账号。
 * 姓名又会变 —— 账号改过名、随心瑜登记的是另一个姓名、历史数据写过昵称 —— 只比
 * `users.name` 就会把这些数据判成「不是本人的」，本人看不到自己的会员/客资/任务。
 *
 * 这里锁定三件事：
 * 1. 规范名与别名都算这个人的；
 * 2. 别名全局唯一，撞车时明确报错而不是静默算错；
 * 3. 业务数据里对不上账号的姓名能被列出来（否则没人知道该补哪条映射）。
 */
class StaffAliasTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name, string $username, string $role = 'R_TEACHER'): User
    {
        return User::factory()->create([
            'name' => $name, 'username' => $username, 'role' => $role, 'roles' => [$role],
            'venue' => '绿地店', 'venues' => ['绿地店'], 'status' => '启用',
        ]);
    }

    private function lead(string $serviceTeacher, string $name = '客户甲'): Lead
    {
        return Lead::create([
            'lead_date' => now()->toDateString(), 'name' => $name, 'phone' => '13800000001',
            'source' => '自然到店', 'venue' => '绿地店', 'service_teacher' => $serviceTeacher,
            'status' => '新留资', 'trial_cards' => [],
        ]);
    }

    public function test_staff_names_includes_canonical_and_aliases(): void
    {
        $u = $this->user('王教练', 'coach-a');
        StaffAlias::create(['user_id' => $u->id, 'alias' => '王老师', 'source' => 'manual']);

        $names = staffNames($u);
        $this->assertContains('王教练', $names);
        $this->assertContains('王老师', $names);
    }

    public function test_teacher_sees_leads_assigned_under_an_alias(): void
    {
        $u = $this->user('王教练', 'coach-a');
        StaffAlias::create(['user_id' => $u->id, 'alias' => '王老师', 'source' => 'manual']);
        $this->lead('王老师', '别名归属的客户');

        Sanctum::actingAs($u);
        $names = collect($this->getJson('/api/leads')->assertOk()->json('data.records'))
            ->pluck('name')
            ->all();

        $this->assertContains('别名归属的客户', $names, '别名归属的留资应该对该老师可见');
    }

    public function test_teacher_cannot_see_leads_of_another_name(): void
    {
        $u = $this->user('王教练', 'coach-a');
        $this->lead('李教练', '别人的客户');

        Sanctum::actingAs($u);
        $names = collect($this->getJson('/api/leads')->assertOk()->json('data.records'))
            ->pluck('name')
            ->all();

        $this->assertNotContains('别人的客户', $names);
    }

    /**
     * 别名归属的留资必须「看得见也改得动」。
     *
     * 可见范围走 staffNames（含别名），而准入校验（canAccessLead）此前只比规范名，
     * 于是改过名/有别名的老师会看到一批点开就 403 的客资 —— 看得见但动不了最难排查。
     */
    public function test_lead_assigned_under_alias_is_editable_not_just_visible(): void
    {
        $u = $this->user('王教练', 'coach-alias-patch');
        StaffAlias::create(['user_id' => $u->id, 'alias' => '王老师', 'source' => 'manual']);
        $lead = $this->lead('王老师', '别名归属的客户');

        Sanctum::actingAs($u);
        $this->patchJson("/api/leads/{$lead->id}", ['remark' => '已电话联系'])
            ->assertOk();

        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'remark' => '已电话联系']);
    }

    public function test_alias_cannot_belong_to_two_accounts(): void
    {
        $a = $this->user('王教练', 'coach-a');
        $this->user('李教练', 'coach-b', 'R_TEACHER');
        StaffAlias::create(['user_id' => $a->id, 'alias' => '王老师', 'source' => 'manual']);

        $super = $this->user('超管', 'boss', 'R_SUPER');
        Sanctum::actingAs($super);

        // 把已属于 A 的别名塞给 B，应被拒并报出冲突名字
        $this->putJson('/api/accounts/coach-b/aliases', ['aliases' => ['王老师']])
            ->assertStatus(422);
    }

    public function test_update_aliases_replaces_and_ignores_canonical_name(): void
    {
        $u = $this->user('王教练', 'coach-a');
        Sanctum::actingAs($this->user('超管', 'boss', 'R_SUPER'));

        // 规范名不用登记（staffNames 恒把它算在内），空串也忽略
        $this->putJson('/api/accounts/coach-a/aliases', ['aliases' => ['王教练', '  ', '王老师']])
            ->assertOk();

        $this->assertSame(['王老师'], StaffAlias::where('user_id', $u->id)->pluck('alias')->all());

        // 再存一次即整体替换
        $this->putJson('/api/accounts/coach-a/aliases', ['aliases' => ['小王']])->assertOk();
        $this->assertSame(['小王'], StaffAlias::where('user_id', $u->id)->pluck('alias')->all());
    }

    public function test_unmapped_names_surface_data_that_belongs_to_nobody(): void
    {
        $this->user('王教练', 'coach-a');
        $this->lead('随心瑜里的另一个名字', '悬空的客户');
        Customer::create([
            'name' => '会员甲', 'phone' => '13800000002', 'phone_tail' => '0002',
            'venue' => '绿地店', 'source' => '自然到店', 'main_card' => '—',
            'layer' => 'P3', 'status' => '跟进中', 'owner' => '王教练',
        ]);

        $unmapped = unmappedStaffNames();
        $this->assertArrayHasKey('随心瑜里的另一个名字', $unmapped);
        // 能对上账号的姓名不该出现在未映射里
        $this->assertArrayNotHasKey('王教练', $unmapped);

        // 「未分配」这类占位符不是人名，不该报成未映射
        $this->lead('未分配', '占位客户');
        $this->assertArrayNotHasKey('未分配', unmappedStaffNames());
    }

    public function test_lead_write_records_owner_user_id_alongside_name(): void
    {
        $u = $this->user('王教练', 'coach-a');
        Sanctum::actingAs($u);

        $this->postJson('/api/leads', [
            'leadDate' => now()->toDateString(),
            'name' => '新客甲',
            'phone' => '13800000009',
            'source' => '自然到店',
            'venue' => '绿地店',
            'serviceTeacher' => '王教练',
        ])->assertOk();

        $lead = Lead::where('name', '新客甲')->firstOrFail();
        $this->assertSame((int) $u->id, (int) $lead->service_teacher_user_id, '写入时应把归属 id 一起落库');
    }

    public function test_ownership_is_union_of_id_and_name(): void
    {
        // 口径：id 或姓名**任一命中**即算本人的。
        // 为什么不"id 优先、姓名仅在 id 为空时兜底"：账号删除重建后老数据的 id 会悬挂，
        // 那种写法会让姓名明明对得上的本人也看不到数据 —— 静默丢数据，且「归属映射」
        // 也发现不了（姓名能对上账号）。宁可重复可见（可被发现），不可静默消失。
        $wang = $this->user('王教练', 'coach-a');
        $li = $this->user('李教练', 'coach-b');

        $this->lead('王教练', 'id 与姓名不一致的行');
        Lead::where('name', 'id 与姓名不一致的行')->update(['service_teacher_user_id' => $li->id]);

        foreach ([$wang, $li] as $who) {
            Sanctum::actingAs($who);
            $names = collect($this->getJson('/api/leads')->assertOk()->json('data.records'))
                ->pluck('name')->all();
            $this->assertContains('id 与姓名不一致的行', $names, 'id 与姓名各命中一方时两边都能看到');
        }
    }

    public function test_stale_owner_user_ids_are_reported(): void
    {
        // 账号删除重建后，老数据会留下指向不存在账号的 id。姓名这条路仍能让本人看到，
        // 但这是需要管理员处理的历史包袱 —— 面板必须把它列出来，否则会一直存在。
        $this->user('王教练', 'coach-a');
        $this->lead('王教练', '悬挂 id 的数据');
        Lead::where('name', '悬挂 id 的数据')->update(['service_teacher_user_id' => 999999]);

        $stale = staleOwnerUserIds();
        $this->assertNotEmpty($stale);
        $this->assertSame('leads', $stale[0]['table']);
        $this->assertSame(999999, $stale[0]['user_id']);
        $this->assertSame(1, $stale[0]['rows']);
    }

    public function test_legacy_row_without_user_id_still_falls_back_to_name(): void
    {
        $u = $this->user('王教练', 'coach-a');
        // 历史行：只有姓名、没有 id（迁移未能回填的情形）
        $this->lead('王教练', '遗留数据');
        $this->assertNull(Lead::where('name', '遗留数据')->value('service_teacher_user_id'));

        Sanctum::actingAs($u);
        $names = collect($this->getJson('/api/leads')->assertOk()->json('data.records'))
            ->pluck('name')->all();
        $this->assertContains('遗留数据', $names, 'id 缺失的历史行仍应靠姓名被本人看到');
    }

    public function test_staff_user_id_refuses_ambiguous_names(): void
    {
        $this->user('王教练', 'coach-a');
        $this->assertSame(1, staffUserId('王教练'));

        // 同名第二个账号出现后，解析必须拒绝而不是猜
        $this->user('王教练', 'coach-c');
        $this->assertNull(staffUserId('王教练'));

        $this->assertNull(staffUserId(''));
        $this->assertNull(staffUserId('查无此人'));
        $this->assertArrayNotHasKey('王教练', staffNameToIdMap(), '歧义姓名不应出现在批量映射里');
    }

    public function test_saving_aliases_backfills_historical_rows(): void
    {
        // 历史行：归属列有名字、但没有 id（迁移回填时对不上任何账号）
        $this->lead('苏米', '老数据');
        $this->assertNull(Lead::where('name', '老数据')->value('service_teacher_user_id'));

        $u = $this->user('新来的苏米', 'sumi');
        Sanctum::actingAs($this->user('超管', 'boss', 'R_SUPER'));

        // 管理员把"苏米"映射到该账号，历史行应当立刻补上 id
        $this->putJson('/api/accounts/sumi/aliases', ['aliases' => ['苏米']])
            ->assertOk()
            ->assertJsonPath('data.backfilled', 1);

        $this->assertSame(
            (int) $u->id,
            (int) Lead::where('name', '老数据')->value('service_teacher_user_id'),
            '补完别名后历史行的归属 id 应被回填'
        );
    }

    public function test_lead_teacher_is_mirrored_from_trial_cards(): void
    {
        // 老师实际填在逐节卡片里；顶层 trial_teacher 是旧版单节字段，必须跟着卡片走 ——
        // 否则（a）列表的上课老师列为空，（b）该老师看不到自己上过的这条留资。
        $ru = $this->user('冰璐', 'coach-bing');
        Sanctum::actingAs($this->user('超管', 'boss', 'R_SUPER'));

        $this->postJson('/api/leads', [
            'leadDate' => now()->toDateString(),
            'name' => '体验客甲',
            'phone' => '13800000011',
            'source' => '美团',
            'venue' => '绿地店',
            'trialCards' => [
                ['session' => 1, 'teacher' => '冰璐', 'topic' => '内观流'],
                ['session' => 2, 'teacher' => '婷婷', 'topic' => '核心床小班'],
            ],
        ])->assertOk();

        $lead = Lead::where('name', '体验客甲')->firstOrFail();
        $this->assertSame('冰璐', $lead->trial_teacher, '顶层老师应取第一节非空的卡片值');
        $this->assertSame((int) $ru->id, (int) $lead->trial_teacher_user_id);

        // 该老师应当能看到这条留资（归属依赖顶层字段）
        Sanctum::actingAs($ru);
        $names = collect($this->getJson('/api/leads')->assertOk()->json('data.records'))
            ->pluck('name')->all();
        $this->assertContains('体验客甲', $names);
    }

    public function test_trial_teacher_follows_card_edits(): void
    {
        $this->user('冰璐', 'coach-bing');
        Sanctum::actingAs($this->user('超管', 'boss', 'R_SUPER'));

        $this->postJson('/api/leads', [
            'leadDate' => now()->toDateString(),
            'name' => '体验客乙',
            'phone' => '13800000012',
            'source' => '美团',
            'venue' => '绿地店',
            'trialCards' => [['session' => 1, 'teacher' => '冰璐']],
        ])->assertOk();

        // 卡片里把老师改掉，顶层要跟着改（不能因为"已有值"就不动）
        $lead = Lead::where('name', '体验客乙')->firstOrFail();
        $this->patchJson("/api/leads/{$lead->id}", [
            'trialCards' => [['session' => 1, 'teacher' => '婷婷']],
        ])->assertOk();

        $this->assertSame('婷婷', $lead->fresh()->trial_teacher);
    }

    public function test_me_payload_exposes_staff_name_separate_from_display_name(): void
    {
        $u = User::factory()->create([
            'name' => '王教练', 'nickname' => '小王', 'username' => 'coach-a',
            'role' => 'R_TEACHER', 'roles' => ['R_TEACHER'],
            'venue' => '绿地店', 'venues' => ['绿地店'], 'status' => '启用',
        ]);

        $info = $u->userInfo();
        // 展示名优先昵称，归属名恒为规范名 —— 前端写归属必须用 staffName，
        // 否则会把自己的昵称写进归属列，而后端按规范名过滤，这条数据对自己消失。
        $this->assertSame('小王', $info['userName']);
        $this->assertSame('王教练', $info['staffName']);
    }
}
