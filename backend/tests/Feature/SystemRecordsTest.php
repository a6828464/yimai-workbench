<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\ModelGenerationRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SystemRecordsTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_updates_nickname_email_and_avatar_safely(): void
    {
        $user = User::factory()->create(['role' => 'R_TEACHER']);
        Sanctum::actingAs($user);
        $avatar = 'data:image/jpeg;base64,'.base64_encode('jpeg');

        $this->putJson('/api/my/profile', [
            'nickname' => '小麦', 'email' => 'new@example.com', 'phone' => '138-0000-1234',
            'avatar' => $avatar, 'profile' => ['gender' => '女'],
        ])->assertOk()->assertJsonPath('data.nickname', '小麦')->assertJsonPath('data.email', 'new@example.com');

        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.userName', '小麦');
        $this->putJson('/api/my/profile', ['email' => 'new@example.com', 'avatar' => 'javascript:alert(1)'])->assertUnprocessable();
    }

    public function test_audit_filters_and_retention_defaults_are_super_only(): void
    {
        $super = User::factory()->create(['role' => 'R_SUPER']);
        $teacher = User::factory()->create(['role' => 'R_TEACHER']);
        AuditLog::create(['operator_id' => $teacher->id, 'operator_name' => $teacher->name, 'operator_role' => '老师', 'action' => '修改', 'module' => '会员管理', 'time' => now(), 'target_id' => '1', 'target_label' => '会员']);
        Sanctum::actingAs($teacher);
        $this->getJson('/api/audit-logs')->assertForbidden();
        Sanctum::actingAs($super);
        $this->getJson('/api/system/retention')->assertOk()
            ->assertJsonPath('data.systemLogDays', 7)
            ->assertJsonPath('data.auditLogDays', 180);
        $this->getJson('/api/audit-logs?action='.urlencode('修改'))->assertOk()
            ->assertJsonCount(1, 'data.records')->assertJsonPath('data.metadata.actions.0', '修改');
    }

    public function test_model_calls_are_recorded_and_super_can_filter_latest_ten(): void
    {
        AppSetting::create(['ai' => ['enabled' => true, 'providerLabel' => '测试', 'baseUrl' => 'https://8.8.8.8/v1', 'apiKey' => 'secret', 'model' => 'model-a']]);
        $teacher = User::factory()->create(['role' => 'R_TEACHER']);
        Http::fake(['https://8.8.8.8/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => '生成结果']]], 'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 3, 'total_tokens' => 5]])]);
        Sanctum::actingAs($teacher);
        $this->postJson('/api/ai/chat', ['featureType' => 'training_plan', 'messages' => [['role' => 'user', 'content' => '生成计划']]])
            ->assertOk()->assertJsonPath('data.content', '生成结果');
        $this->assertDatabaseHas('model_generation_records', ['user_id' => $teacher->id, 'feature_type' => 'training_plan', 'status' => 'success', 'total_tokens' => 5]);

        for ($i = 0; $i < 11; $i++) {
            ModelGenerationRecord::create(['request_id' => (string) Str::uuid(), 'user_id' => $teacher->id, 'operator_name' => $teacher->name, 'operator_role' => $teacher->role, 'feature_type' => 'chat', 'source' => 'llm', 'status' => 'success']);
        }
        $super = User::factory()->create(['role' => 'R_SUPER']);
        Sanctum::actingAs($super);
        $this->getJson('/api/model-generations')->assertOk()->assertJsonCount(10, 'data.records')->assertJsonPath('data.size', 10);
    }
}
