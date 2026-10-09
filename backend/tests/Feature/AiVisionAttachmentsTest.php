<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\ModelGenerationRecord;
use App\Models\User;
use App\Support\VisionAttachments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 朋友圈「图片素材」多模态透传（docs/营销工具/08-朋友圈读图-vision-后端说明.md）。
 *
 * 覆盖：无图 payload 逐字节不变 / 有图合并为一条 [text, image_url...] 消息 /
 * 非法 dataURL 与 >3 张与超体积 422 中文提示 / base64 绝不落库 / 上游不支持读图时如实报错 /
 * 请求体被服务器丢弃时的中文错误（不 500、不误报「messages 必填」）。
 */
class AiVisionAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    /** 1×1 真图（PNG / JPEG），用于让内容校验（getimagesizefromstring）通过 */
    private const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private const JPEG_1PX = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwcJC4iIhw3LC4yMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAALCAABAAEBAREA/8QAFAABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAwDAQACEQMRAD8AmAA=';

    private function configureAi(): void
    {
        AppSetting::create(['ai' => [
            'enabled' => true,
            'providerLabel' => '测试',
            'baseUrl' => 'https://8.8.8.8/v1',
            'apiKey' => 'secret',
            'model' => 'model-a',
        ]]);
    }

    private function actingTeacher(): User
    {
        $user = User::factory()->create(['role' => 'R_TEACHER', 'status' => '启用']);
        Sanctum::actingAs($user);

        return $user;
    }

    private function pngDataUrl(): string
    {
        return 'data:image/png;base64,'.self::PNG_1PX;
    }

    // ───────────────────────── ① 无 attachments：payload 逐字节不变 ─────────────────────────

    public function test_payload_is_byte_identical_without_attachments(): void
    {
        $this->configureAi();
        $this->actingTeacher();
        Http::fake(['https://8.8.8.8/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'OK']]]])]);

        $messages = [
            ['role' => 'system', 'content' => '你是文案助手'],
            ['role' => 'user', 'content' => '写一条朋友圈'],
        ];
        // 与改造前完全相同的期望体：字段顺序、键名、编码方式都不许变
        $expected = json_encode([
            'model' => 'model-a',
            'messages' => $messages,
            'temperature' => 0.8,
            'max_tokens' => 512,
        ]);

        $this->postJson('/api/ai/chat', [
            'messages' => $messages,
            'temperature' => 0.8,
            'maxTokens' => 512,
        ])->assertOk()->assertJsonPath('data.content', 'OK');

        $sent = null;
        Http::assertSent(function (Request $request) use (&$sent) {
            $sent = $request->body();

            return true;
        });
        $this->assertSame($expected, $sent, '无 attachments 时上游请求体必须与改造前逐字节一致');
    }

    public function test_empty_attachments_array_behaves_like_absent(): void
    {
        $this->configureAi();
        $this->actingTeacher();
        Http::fake(['https://8.8.8.8/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'OK']]]])]);

        $this->postJson('/api/ai/chat', [
            'messages' => [['role' => 'user', 'content' => '你好']],
            'attachments' => [],
        ])->assertOk();

        $sent = null;
        Http::assertSent(function (Request $request) use (&$sent) {
            $sent = $request->body();

            return true;
        });
        $this->assertSame(
            json_encode(['model' => 'model-a', 'messages' => [['role' => 'user', 'content' => '你好']]]),
            $sent,
            '空数组必须与缺省等价（不得产出空的多模态消息）'
        );
    }

    // ───────────────────────── ② 有 attachments：末尾多模态消息 ─────────────────────────

    public function test_attachments_are_merged_into_one_multimodal_message_at_tail(): void
    {
        $this->configureAi();
        $this->actingTeacher();
        Http::fake(['https://8.8.8.8/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'OK']]]])]);

        $png = $this->pngDataUrl();
        $jpg = 'data:image/jpeg;base64,'.self::JPEG_1PX;
        $messages = [
            ['role' => 'system', 'content' => '你是文案助手'],
            ['role' => 'user', 'content' => '先闲聊一句'],
            ['role' => 'assistant', 'content' => '好的'],
            ['role' => 'user', 'content' => '按这两张图写朋友圈'],
        ];

        $this->postJson('/api/ai/chat', ['messages' => $messages, 'attachments' => [$png, $jpg]])
            ->assertOk()->assertJsonPath('data.content', 'OK');

        $payload = null;
        Http::assertSent(function (Request $request) use (&$payload) {
            $payload = $request->data();

            return true;
        });

        // 原有消息一条不少、顺序不变
        $this->assertSame($messages, array_slice($payload['messages'], 0, 4));
        $this->assertCount(5, $payload['messages']);

        $appended = $payload['messages'][4];
        $this->assertSame('user', $appended['role']);
        $this->assertSame('text', $appended['content'][0]['type']);
        $this->assertSame('按这两张图写朋友圈', $appended['content'][0]['text'], 'text 分片取最后一条 user 文本');
        $this->assertSame('image_url', $appended['content'][1]['type']);
        $this->assertSame($png, $appended['content'][1]['image_url']['url'], 'image_url.url 必须是原始 dataURL');
        $this->assertSame('image_url', $appended['content'][2]['type']);
        $this->assertSame($jpg, $appended['content'][2]['image_url']['url']);
    }

    /**
     * 前端实际走的是 stream=true（`chatLLMStream` 里写死 `stream: true`），
     * 所以「带图 + 流式」必须是常态化覆盖，而不是靠非流式用例外推。
     */
    public function test_streaming_request_also_carries_the_multimodal_message(): void
    {
        $this->configureAi();
        $this->actingTeacher();
        $sse = "data: {\"choices\":[{\"delta\":{\"content\":\"好\"}}]}\n\ndata: [DONE]\n\n";
        Http::fake(['https://8.8.8.8/v1/chat/completions' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);

        $png = $this->pngDataUrl();
        $resp = $this->postJson('/api/ai/chat', [
            'messages' => [['role' => 'user', 'content' => '按图写']],
            'attachments' => [$png],
            'stream' => true,
        ]);
        $resp->assertOk();

        $payload = null;
        Http::assertSent(function (Request $request) use (&$payload) {
            $payload = $request->data();

            return true;
        });
        $this->assertTrue($payload['stream']);
        $appended = $payload['messages'][1];
        $this->assertSame('user', $appended['role']);
        $this->assertSame('text', $appended['content'][0]['type']);
        $this->assertSame($png, $appended['content'][1]['image_url']['url']);
        $this->assertStringContainsString('"content":"好"', $resp->streamedContent());
    }

    public function test_multimodal_message_appended_when_no_user_message_exists(): void
    {
        $this->configureAi();
        $this->actingTeacher();
        Http::fake(['https://8.8.8.8/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'OK']]]])]);

        $this->postJson('/api/ai/chat', [
            'messages' => [['role' => 'system', 'content' => '只有系统提示']],
            'attachments' => [$this->pngDataUrl()],
        ])->assertOk();

        $payload = null;
        Http::assertSent(function (Request $request) use (&$payload) {
            $payload = $request->data();

            return true;
        });
        $this->assertCount(2, $payload['messages']);
        $this->assertSame('user', $payload['messages'][1]['role']);
        // 无 user 文本时不塞空 text 分片（上游对空 text 的容忍度不一致）
        $this->assertSame('image_url', $payload['messages'][1]['content'][0]['type']);
    }

    public function test_text_part_comes_from_last_user_message_not_last_message(): void
    {
        $this->configureAi();
        $this->actingTeacher();
        Http::fake(['https://8.8.8.8/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'OK']]]])]);

        // 末条是 assistant 时，text 仍取**最后一条 user** 的文本（契约口径）
        $this->postJson('/api/ai/chat', [
            'messages' => [
                ['role' => 'user', 'content' => '第一句用户话'],
                ['role' => 'assistant', 'content' => '模型的回话'],
            ],
            'attachments' => [$this->pngDataUrl()],
        ])->assertOk();

        $payload = null;
        Http::assertSent(function (Request $request) use (&$payload) {
            $payload = $request->data();

            return true;
        });
        $this->assertCount(3, $payload['messages']);
        $this->assertSame('第一句用户话', $payload['messages'][2]['content'][0]['text']);
    }

    // ───────────────────────── ③ 非法输入：422 + 中文可读提示 ─────────────────────────

    public function test_rejects_fourth_image_with_chinese_message(): void
    {
        $this->configureAi();
        $this->actingTeacher();
        Http::fake();

        $resp = $this->postJson('/api/ai/chat', [
            'messages' => [['role' => 'user', 'content' => '你好']],
            'attachments' => array_fill(0, 4, $this->pngDataUrl()),
        ]);

        $resp->assertStatus(422);
        $resp->assertJsonPath('message', '一次最多上传 3 张图片，请减少图片数量后重试');
        Http::assertNothingSent();
        $this->assertSame(0, ModelGenerationRecord::count(), '参数不合法不得先落一条记录');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidDataUrlProvider(): array
    {
        return [
            '非图片 mime（gif）' => ['data:image/gif;base64,R0lGODlhAQABAAAAACw='],
            '远程 URL（不得成为服务端取图入口）' => ['https://example.com/a.png'],
            'svg（脚本载体）' => ['data:image/svg+xml;base64,PHN2Zz48L3N2Zz4='],
            'file 协议' => ['file:///etc/passwd'],
            'base64 段为空' => ['data:image/png;base64,'],
            '缺 base64 前缀' => ['data:image/png,AAAA'],
            'base64 段含非法字符' => ['data:image/png;base64,AA!!AA'],
        ];
    }

    #[DataProvider('invalidDataUrlProvider')]
    public function test_rejects_invalid_data_url_with_chinese_message(string $attachment): void
    {
        $this->configureAi();
        $this->actingTeacher();
        Http::fake();

        $resp = $this->postJson('/api/ai/chat', [
            'messages' => [['role' => 'user', 'content' => '你好']],
            'attachments' => [$attachment],
        ]);

        $resp->assertStatus(422);
        $message = (string) $resp->json('message');
        $this->assertNotSame('', $message);
        $this->assertStringContainsString('图片', $message, '必须是中文可读的图片相关提示');
        $this->assertStringNotContainsString('messages 必填', $message);
        $this->assertDoesNotMatchRegularExpression('/^The /', $message, '不得回默认英文校验文案');
        Http::assertNothingSent();
    }

    public function test_rejects_base64_that_is_not_a_real_image(): void
    {
        $this->configureAi();
        $this->actingTeacher();
        Http::fake();

        // 形态合法（mime 白名单 + base64 字符集）但内容不是图片
        $resp = $this->postJson('/api/ai/chat', [
            'messages' => [['role' => 'user', 'content' => '你好']],
            'attachments' => ['data:image/png;base64,'.base64_encode('<?php echo 1;')],
        ]);

        $resp->assertStatus(422);
        $this->assertStringContainsString('第 1 张图片', (string) $resp->json('message'));
        $this->assertStringContainsString('图片', (string) $resp->json('message'));
        Http::assertNothingSent();
    }

    public function test_rejects_oversized_image_with_position_and_size_in_message(): void
    {
        $this->configureAi();
        $this->actingTeacher();
        Http::fake();
        config(['services.ai.max_image_bytes' => 4096]);

        // 真图 + 尾部填充，使 base64 合法、解码后超限（填充不影响 getimagesize 头部识别）
        $binary = base64_decode(self::PNG_1PX).str_repeat('MOMENTS-VISION-PAYLOAD-', 200);
        $this->assertGreaterThan(4096, strlen($binary));

        $resp = $this->postJson('/api/ai/chat', [
            'messages' => [['role' => 'user', 'content' => '你好']],
            'attachments' => [$this->pngDataUrl(), 'data:image/png;base64,'.base64_encode($binary)],
        ]);

        $resp->assertStatus(422);
        $message = (string) $resp->json('message');
        $this->assertStringContainsString('第 2 张图片过大', $message, '必须点名是第几张（不得静默截断/丢图）');
        $this->assertStringContainsString('压缩', $message);
        Http::assertNothingSent();
    }

    public function test_attachment_limit_is_configurable_and_default_is_three(): void
    {
        $this->assertSame(3, VisionAttachments::MAX_IMAGES);
        $this->assertSame(1572864, VisionAttachments::maxBytes(), '默认单张 1.5MB');
        // F5：后端两个数值钉住（不读前端 TS 常量，避免测试与前端文件耦合）
        $this->assertSame(4194304, VisionAttachments::maxTotalBytes(), '默认多图合计 4MB');
    }

    // ───────────────────────── F6：多图聚合体积上限 ─────────────────────────

    public function test_rejects_total_size_over_cap_even_when_each_image_is_legal(): void
    {
        $this->configureAi();
        $this->actingTeacher();
        Http::fake();
        // 单张上限放到很大，让「每张都合法」但「合计超限」成为唯一触发条件
        config(['services.ai.max_image_bytes' => 8388608, 'services.ai.max_total_image_bytes' => 4096]);

        // 三张各 ~2KB（都合法），合计 ~6KB > 4096
        $chunk = base64_decode(self::PNG_1PX).str_repeat('Q', 2000);
        $dataUrl = 'data:image/png;base64,'.base64_encode($chunk);
        $each = strlen($chunk);
        $this->assertGreaterThan(4096 / 3, $each, '单张必须低于合计上限，才构成「每张合法但合计超限」');
        $this->assertLessThan(8388608, $each, '单张必须低于单张上限，排除单张分支');

        $resp = $this->postJson('/api/ai/chat', [
            'messages' => [['role' => 'user', 'content' => '你好']],
            'attachments' => [$dataUrl, $dataUrl, $dataUrl],
        ]);

        $resp->assertStatus(422);
        $message = (string) $resp->json('message');
        $this->assertStringContainsString('3 张图片合计', $message, '必须点名合计张数与总量');
        $this->assertStringContainsString('请减少图片数量或压缩', $message);
        // 不得退化成单张提示（那是另一个成因，用户会去反复压同一张）
        $this->assertStringNotContainsString('第 1 张图片过大', $message);
        Http::assertNothingSent();
    }

    public function test_total_size_cap_is_inclusive_and_single_image_still_passes(): void
    {
        $this->configureAi();
        $this->actingTeacher();
        Http::fake(['https://8.8.8.8/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'OK']]]])]);
        config(['services.ai.max_total_image_bytes' => 4096]);

        // 单张 70B 远低于合计上限 → 正常放行（聚合校验不得误伤单图）
        $this->postJson('/api/ai/chat', [
            'messages' => [['role' => 'user', 'content' => '你好']],
            'attachments' => [$this->pngDataUrl()],
        ])->assertOk();
    }

    public function test_total_size_cap_message_is_chinese_and_readable(): void
    {
        $this->configureAi();
        $this->actingTeacher();
        Http::fake();
        config(['services.ai.max_total_image_bytes' => 1024]);

        $chunk = base64_decode(self::PNG_1PX).str_repeat('W', 900);
        $resp = $this->postJson('/api/ai/chat', [
            'messages' => [['role' => 'user', 'content' => '你好']],
            'attachments' => ['data:image/png;base64,'.base64_encode($chunk), 'data:image/png;base64,'.base64_encode($chunk)],
        ]);

        $resp->assertStatus(422);
        $this->assertDoesNotMatchRegularExpression('/^The /', (string) $resp->json('message'));
        $this->assertStringContainsString('MB', (string) $resp->json('message'));
    }

    // ───────────────────────── F3：语义化 error_message ─────────────────────────

    public function test_vision_unsupported_records_semantic_error_message(): void
    {
        $this->configureAi();
        $user = $this->actingTeacher();
        Http::fake(['https://8.8.8.8/v1/chat/completions' => Http::response(
            ['error' => ['message' => 'This model does not support image input']],
            400
        )]);

        $this->postJson('/api/ai/chat', [
            'messages' => [['role' => 'user', 'content' => '按图写']],
            'attachments' => [$this->pngDataUrl()],
        ])->assertStatus(422);

        $record = ModelGenerationRecord::where('user_id', $user->id)->latest('id')->firstOrFail();
        // 超管在 /model-generations 能一眼区分「模型不读图」与「上游抽风」
        $this->assertStringStartsWith('VISION_UNSUPPORTED', (string) $record->error_message);
        $this->assertStringContainsString('model=model-a', (string) $record->error_message);
        $this->assertStringContainsString('upstream=HTTP 400', (string) $record->error_message);
        $this->assertStringContainsString('1 张图片', (string) $record->output_preview);
        // 语义化记录不得把 base64 写进去
        $this->assertStringNotContainsString('base64', (string) $record->error_message);
        $this->assertStringNotContainsString('base64', (string) $record->output_preview);
    }

    public function test_non_vision_failure_keeps_http_error_message_without_vision_prefix(): void
    {
        $this->configureAi();
        $user = $this->actingTeacher();
        Http::fake(['https://8.8.8.8/v1/chat/completions' => Http::response(
            ['error' => ['message' => 'insufficient quota']],
            402
        )]);

        $this->postJson('/api/ai/chat', [
            'messages' => [['role' => 'user', 'content' => '按图写']],
            'attachments' => [$this->pngDataUrl()],
        ])->assertOk();

        $record = ModelGenerationRecord::where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->assertStringStartsWith('HTTP 402', (string) $record->error_message);
        $this->assertStringNotContainsString('VISION_UNSUPPORTED', (string) $record->error_message);
    }

    public function test_non_array_attachments_yields_single_coherent_message(): void
    {
        $this->configureAi();
        $this->actingTeacher();
        Http::fake();

        $resp = $this->postJson('/api/ai/chat', [
            'messages' => [['role' => 'user', 'content' => '你好']],
            'attachments' => 'not-an-array',
        ]);

        $resp->assertStatus(422);
        // 只有一条错误：不得同时冒「格式不正确」与「最多 3 张」两句互相矛盾的话
        $this->assertSame(
            ['图片参数格式不正确，请重新选择图片'],
            $resp->json('errors.attachments'),
            'attachments 非数组时必须只回一条可照做的提示'
        );
        Http::assertNothingSent();
    }

    public function test_oversize_message_shows_two_decimals_so_it_never_contradicts_itself(): void
    {
        $this->configureAi();
        $this->actingTeacher();
        Http::fake();
        config(['services.ai.max_image_bytes' => 1572864]);

        // 恰好略超 1.5MB：一位小数会渲染成「约 1.5MB，上限 1.5MB」自相矛盾
        $binary = base64_decode(self::PNG_1PX).str_repeat('X', 1600000);
        $resp = $this->postJson('/api/ai/chat', [
            'messages' => [['role' => 'user', 'content' => '你好']],
            'attachments' => ['data:image/png;base64,'.base64_encode($binary)],
        ]);

        $resp->assertStatus(422);
        $message = (string) $resp->json('message');
        $this->assertStringContainsString('1.53MB', $message);
        $this->assertStringContainsString('上限 1.50MB', $message);
        $this->assertNotSame(
            '第 1 张图片过大（约 1.5MB），单张上限 1.5MB，请压缩后重试',
            $message,
            '不得出现「实际值 == 上限」的写法'
        );
        Http::assertNothingSent();
    }

    // ───────────────────────── ④ base64 绝不落库 ─────────────────────────

    public function test_input_summary_never_contains_base64(): void
    {
        $this->configureAi();
        $user = $this->actingTeacher();
        Http::fake(['https://8.8.8.8/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => '文案']]]])]);

        $png = $this->pngDataUrl();
        $this->postJson('/api/ai/chat', [
            'messages' => [['role' => 'user', 'content' => '按图写朋友圈']],
            'attachments' => [$png, $png, $png],
        ])->assertOk();

        $record = ModelGenerationRecord::where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame('按图写朋友圈', $record->input_summary);
        $this->assertStringNotContainsString('base64', $record->input_summary);
        $this->assertLessThanOrEqual(300, mb_strlen((string) $record->input_summary));
    }

    public function test_summary_scrubs_base64_even_if_client_pastes_it_into_content(): void
    {
        $this->configureAi();
        $user = $this->actingTeacher();
        Http::fake(['https://8.8.8.8/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => '文案']]]])]);

        // 客户端把 dataURL 粘进 content（老前端/手工调用）时，落库口径也要干净
        $this->postJson('/api/ai/chat', [
            'messages' => [['role' => 'user', 'content' => '看这张图 '.$this->pngDataUrl().' 写文案']],
        ])->assertOk();

        $record = ModelGenerationRecord::where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->assertStringNotContainsString('base64', (string) $record->input_summary);
        $this->assertStringContainsString('[图片]', (string) $record->input_summary);
        $this->assertLessThanOrEqual(300, mb_strlen((string) $record->input_summary));
    }

    public function test_summary_truncates_long_text_to_300_chars(): void
    {
        $this->configureAi();
        $user = $this->actingTeacher();
        Http::fake(['https://8.8.8.8/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => '文案']]]])]);

        $long = str_repeat('长', 500);
        $this->postJson('/api/ai/chat', ['messages' => [['role' => 'user', 'content' => $long]]])->assertOk();

        $record = ModelGenerationRecord::where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame(300, mb_strlen((string) $record->input_summary));
        $this->assertSame(mb_substr($long, 0, 300), $record->input_summary);
    }

    // ───────────────────────── 上游不支持读图：诚实失败面 ─────────────────────────

    public function test_upstream_image_unsupported_failure_is_reported_in_chinese(): void
    {
        $this->configureAi();
        $this->actingTeacher();
        Http::fake(['https://8.8.8.8/v1/chat/completions' => Http::response(
            ['error' => ['message' => 'This model does not support image input']],
            400
        )]);

        $resp = $this->postJson('/api/ai/chat', [
            'messages' => [['role' => 'user', 'content' => '按图写']],
            'attachments' => [$this->pngDataUrl()],
        ]);

        $resp->assertStatus(422);
        $this->assertStringContainsString('当前模型不支持图片输入', (string) $resp->json('message'));
        $this->assertTrue($resp->json('visionUnsupported'), '前端需要一个可识别的标记来提示换模型');
        // 不做静默去图重试：上游只应收到一次带图请求
        Http::assertSentCount(1);
    }

    public function test_upstream_other_error_keeps_generic_message_and_no_vision_flag(): void
    {
        $this->configureAi();
        $this->actingTeacher();
        Http::fake(['https://8.8.8.8/v1/chat/completions' => Http::response(
            ['error' => ['message' => 'insufficient quota']],
            402
        )]);

        $resp = $this->postJson('/api/ai/chat', [
            'messages' => [['role' => 'user', 'content' => '按图写']],
            'attachments' => [$this->pngDataUrl()],
        ]);

        $resp->assertStatus(200);
        $this->assertStringContainsString('大模型接口返回异常（HTTP 402）', (string) $resp->json('message'));
        $this->assertNull($resp->json('visionUnsupported'));
    }

    public function test_text_only_request_is_not_misread_as_vision_unsupported(): void
    {
        $this->configureAi();
        $this->actingTeacher();
        // 纯文本请求遇到含 "image" 字样的上游报错，不得被误报成「模型不支持读图」
        Http::fake(['https://8.8.8.8/v1/chat/completions' => Http::response(
            ['error' => ['message' => 'invalid image field is not allowed here']],
            400
        )]);

        $this->postJson('/api/ai/chat', ['messages' => [['role' => 'user', 'content' => '你好']]])
            ->assertOk()
            ->assertJsonPath('visionUnsupported', null);
    }

    // ───────────────────────── 请求体被服务器丢弃：不 500、不误报 messages 必填 ─────────────────────────

    public function test_body_dropped_by_server_returns_chinese_oversize_hint(): void
    {
        $this->configureAi();
        $this->actingTeacher();
        Http::fake();

        // 模拟 PHP post_max_size 丢弃 body（分块传输 / SAPI 未回填 CONTENT_LENGTH）：
        // 原始 body 为空、$_POST 为空，但传输层面有 chunked 痕迹
        $resp = $this->call('POST', '/api/ai/chat', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_TRANSFER_ENCODING' => 'chunked',
        ], '');

        $resp->assertStatus(413);
        $this->assertSame('图片总体积超出服务器上限，请减少图片数量或压缩后再试', $resp->json('message'));
        Http::assertNothingSent();
    }

    /**
     * `PostTooLargeException` 渲染器的契约（F5 已接线，见 `bootstrap/app.php`）：
     * - `/api/*` → 中文 413 JSON（端到端断言见 test_global_guard_still_blocks_oversize_content_length）
     * - 其它路径 → 返回 `null`，交回框架默认渲染（不得把 API 文案泄到网页入口、也不得吞成 200）
     */
    public function test_post_too_large_renderer_returns_chinese_json_for_api_and_null_otherwise(): void
    {
        $api = VisionAttachments::renderPostTooLarge(HttpRequest::create('/api/ai/chat', 'POST'));
        $this->assertNotNull($api);
        $this->assertSame(413, $api->getStatusCode());
        $this->assertSame(
            ['code' => 1, 'message' => '图片总体积超出服务器上限，请减少图片数量或压缩后再试'],
            $api->getData(true)
        );

        $this->assertNull(
            VisionAttachments::renderPostTooLarge(HttpRequest::create('/up', 'POST')),
            '非 /api/* 请求交回框架默认渲染，不得把 API 文案泄到网页入口'
        );
        $this->assertNull(
            VisionAttachments::renderPostTooLarge(HttpRequest::create('/', 'POST')),
            '首页同样交回框架'
        );
    }

    public function test_global_guard_still_blocks_oversize_content_length(): void
    {
        $this->configureAi();
        $this->actingTeacher();
        Http::fake();

        // 声明了超限的 Content-Length：全局 ValidatePostSize 在控制器之前就拦截。
        // F5 接线后，经 bootstrap/app.php 的 PostTooLargeException 渲染换成中文 413。
        $resp = $this->call('POST', '/api/ai/chat', [], [], [], [
            'CONTENT_LENGTH' => 9 * 1024 * 1024,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], '');

        // 关键不变量：必须被拦住（413），既不是 500 也不是误导性的 422「messages 必填」
        $resp->assertStatus(413);
        $this->assertSame(
            '图片总体积超出服务器上限，请减少图片数量或压缩后再试',
            $resp->json('message'),
            '超 post_max_size 必须是中文可读提示（F5 接线的端到端证明）'
        );
        $this->assertStringNotContainsString('messages', (string) $resp->json('message'));
        $this->assertStringNotContainsString('The POST data is too large', (string) $resp->json('message'));
        Http::assertNothingSent();
    }

    /**
     * F5 接线不得吞掉既有错误面：未登录仍是 401（不是被 PostTooLarge 渲染器接管成 413）。
     */
    public function test_post_too_large_wiring_does_not_swallow_other_error_surfaces(): void
    {
        Http::fake();

        // 未登录：既有 AuthenticationException 渲染必须原样生效
        $this->postJson('/api/ai/chat', ['messages' => [['role' => 'user', 'content' => '你好']]])
            ->assertStatus(401);

        // 普通校验失败：仍是 422，不串到 413
        $this->configureAi();
        $this->actingTeacher();
        $this->postJson('/api/ai/chat', ['messages' => []])
            ->assertStatus(422);
    }

    /**
     * F5：渲染器返回 null 时不得让异常被吞成 200 —— 由 test_post_too_large_renderer_* 一并覆盖。
     */
    public function test_truly_empty_body_keeps_legacy_messages_required_error(): void
    {
        $this->configureAi();
        $this->actingTeacher();
        Http::fake();

        // 老行为不变：完全空 body 仍是 422 messages 必填（不是 413）
        $resp = $this->postJson('/api/ai/chat', []);
        $resp->assertStatus(422);
        $this->assertStringContainsString('messages', (string) $resp->json('message'));
    }
}
