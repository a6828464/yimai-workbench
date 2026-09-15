<?php

namespace Tests\Feature;

use App\Models\TrainingPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 训练计划逐条读写。
 *
 * 背景：计划有两个创建入口 —— 前端新建、课后分析流转（服务端落库）。
 * 原来是「前端整表提交 + 服务端整表替换」，任何一份较早的客户端列表提交上来
 * 都会把服务端刚写入的那份覆盖掉（表现为「点了转训练计划，到训练计划里却找不到」）。
 * 现改为逐条 upsert + 显式删除。
 */
class TrainingPlanTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name, string $username, string $role = 'R_TEACHER'): User
    {
        return User::factory()->create([
            'name' => $name, 'username' => $username, 'role' => $role, 'roles' => [$role],
            'venue' => '绿地店', 'venues' => ['绿地店'], 'status' => '启用',
        ]);
    }

    private function planPayload(int $id, string $member, string $goal = '改善体态'): array
    {
        return [
            'id' => $id,
            'memberName' => $member,
            'coreGoal' => $goal,
            'status' => '待老师确认',
            'content' => ['summary' => 's', 'phases' => [], 'cautions' => []],
        ];
    }

    public function test_create_update_delete_single_plan(): void
    {
        Sanctum::actingAs($this->user('王教练', 'coach-a'));

        // 新建
        $id = $this->postJson('/api/training-plans', ['plan' => $this->planPayload(1, '甲')])
            ->assertOk()->json('data.serverId');
        $this->assertDatabaseHas('training_plans', ['id' => $id, 'member_name' => '甲']);

        // 更新
        $this->putJson("/api/training-plans/{$id}", [
            'plan' => $this->planPayload(1, '甲', '新的目标'),
        ])->assertOk();
        $this->assertSame('新的目标', TrainingPlan::find($id)->payload['coreGoal']);

        // 删除
        $this->deleteJson("/api/training-plans/{$id}")->assertOk();
        $this->assertDatabaseMissing('training_plans', ['id' => $id]);
    }

    public function test_cannot_touch_other_users_plan(): void
    {
        $owner = $this->user('王教练', 'coach-a');
        TrainingPlan::create([
            'member_name' => '别人的', 'payload' => $this->planPayload(1, '别人的'),
            'status' => '待老师确认', 'created_by' => '别人',
        ]);
        $row = TrainingPlan::where('member_name', '别人的')->firstOrFail();

        Sanctum::actingAs($owner);
        $this->putJson("/api/training-plans/{$row->id}", ['plan' => $this->planPayload(1, '改')])
            ->assertStatus(404);
        $this->deleteJson("/api/training-plans/{$row->id}")->assertStatus(404);
        $this->assertDatabaseHas('training_plans', ['id' => $row->id]);
    }

    /** 只提交变化的行，未提交的行（含服务端生成的）不受影响 */
    public function test_bulk_only_touches_submitted_rows(): void
    {
        Sanctum::actingAs($this->user('王教练', 'coach-a'));

        // 服务端生成一份（模拟课后分析流转）
        $serverPlan = TrainingPlan::create([
            'member_name' => '流转学员', 'payload' => $this->planPayload(0, '流转学员'),
            'status' => '待老师确认', 'created_by' => '王教练', 'source_review_id' => 99,
        ]);

        // 前端只提交自己那一行
        $res = $this->putJson('/api/training-plans/bulk', [
            'plans' => [$this->planPayload(500, '前端新建')],
            'deletedIds' => [],
        ])->assertOk()->json('data');

        $this->assertCount(1, $res['ids']);
        $this->assertDatabaseHas('training_plans', ['id' => $serverPlan->id]);
        $this->assertSame(99, TrainingPlan::find($serverPlan->id)->source_review_id);

        // 空提交是安全的：什么都不删
        $this->putJson('/api/training-plans/bulk', ['plans' => [], 'deletedIds' => []])->assertOk();
        $this->assertDatabaseHas('training_plans', ['id' => $serverPlan->id]);
    }

    /** 客户端 id 被别的账号占用时，服务端另分配主键并回传映射 */
    public function test_client_id_collision_is_reassigned(): void
    {
        TrainingPlan::create([
            'id' => 777, 'member_name' => '占位', 'payload' => ['id' => 777, 'memberName' => '占位'],
            'status' => '待老师确认', 'created_by' => '别的账号',
        ]);

        Sanctum::actingAs($this->user('王教练', 'coach-a'));
        $res = $this->postJson('/api/training-plans', ['plan' => $this->planPayload(777, '我的新手')])
            ->assertOk()->json('data');

        $this->assertSame(777, $res['clientId']);
        $this->assertNotSame(777, $res['serverId']);
        // 占位那条没有被改动
        $this->assertSame('占位', TrainingPlan::find(777)->member_name);
        $this->assertSame('我的新手', TrainingPlan::find($res['serverId'])->member_name);
    }
}
