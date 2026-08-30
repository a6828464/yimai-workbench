<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_ignores_client_destination_key_and_model(): void
    {
        AppSetting::create(['ai' => [
            'enabled' => true,
            'baseUrl' => 'https://8.8.8.8/v1',
            'apiKey' => 'saved-secret',
            'model' => 'saved-model',
        ]]);
        Sanctum::actingAs(User::factory()->create(['role' => 'R_TEACHER', 'status' => '启用']));
        Http::fake([
            'https://8.8.8.8/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'OK']]],
            ]),
        ]);

        $this->postJson('/api/ai/chat', [
            'baseUrl' => 'https://attacker.example/v1',
            'apiKey' => 'attacker-key',
            'model' => 'attacker-model',
            'messages' => [['role' => 'user', 'content' => '你好']],
        ])->assertOk()->assertJsonPath('data.content', 'OK');

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://8.8.8.8/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer saved-secret')
                && $request['model'] === 'saved-model';
        });
    }

    public function test_chat_rejects_saved_private_destination_without_sending_key(): void
    {
        AppSetting::create(['ai' => [
            'enabled' => true,
            'baseUrl' => 'https://127.0.0.1/v1',
            'apiKey' => 'saved-secret',
            'model' => 'saved-model',
        ]]);
        Sanctum::actingAs(User::factory()->create(['role' => 'R_MEDIA', 'status' => '启用']));
        Http::fake();

        $this->postJson('/api/ai/chat', [
            'messages' => [['role' => 'user', 'content' => '你好']],
        ])->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_only_super_can_probe_models_and_private_urls_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'R_TEACHER', 'status' => '启用']));
        $this->postJson('/api/ai/models', [
            'baseUrl' => 'https://8.8.8.8/v1',
            'apiKey' => 'key',
        ])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'R_SUPER', 'status' => '启用']));
        $this->putJson('/api/ai/config', [
            'enabled' => true,
            'providerLabel' => '测试',
            'baseUrl' => 'https://10.0.0.1/v1',
            'apiKey' => 'key',
            'model' => 'model',
            'temperature' => 0.8,
        ])->assertUnprocessable();
    }
}
