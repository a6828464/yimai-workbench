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
