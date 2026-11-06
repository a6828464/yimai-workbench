<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * B2-2 中文校验文案 + B2-3 AI 上游失败的状态码（t15）。
 *
 * 这两件事都是「后端回给前端的东西让人看不懂」：
 *  - 缺 `lang/zh_CN` 时，54 处 `$r->validate()` 全返回 "The name field is required."，
 *    小程序 toast 把它原样透给员工；
 *  - AI 控制器的 7 处失败原本是 **HTTP 200 + code:1**，非流式调用靠 unwrap 勉强能读，
 *    而**流式调用会塌**：admin-web 的 readSse 只判 `resp.ok`，200 就按 SSE 逐行读，
 *    非 `data:` 行被丢弃 → 内容为空 → 抛 `LLM_EMPTY_RESPONSE`，真实原因被吞掉。
 *
 * 所以这里两段断言：中文文案（≥3 个字段）+ 上游失败必须回 502（而不是 200）。
 */
class ValidationLocaleAndAiStatusTest extends TestCase
{
    use RefreshDatabase;

    // ==================================================== 1. 中文校验文案

    /**
     * 留资必填校验：三个字段（姓名 / 来源渠道 / 门店）都必须回中文。
     *
     * 走真实接口而不是只调 `__()`：`__()` 能过而 Validator 不认 `validation.attributes`
     * 是最常见的假绿（本文件第一版就踩过），所以断言直接读 422 的 errors 原文。
     */
    public function test_lead_validation_errors_are_chinese(): void
    {
        $this->assertSame('zh_CN', config('app.locale'), '前提：APP_LOCALE 必须是 zh_CN（.env / .env.example 已设）');

        Sanctum::actingAs($this->super());

        $resp = $this->postJson('/api/leads', [])->assertStatus(422);
        $errors = $resp->json('errors');

        $this->assertSame('请填写 姓名。', $errors['name'][0] ?? null, 'name 的中文文案');
        $this->assertSame('请填写 来源渠道。', $errors['source'][0] ?? null, 'source 的中文文案');
        $this->assertSame('请填写 门店。', $errors['venue'][0] ?? null, 'venue 的中文文案');

        // 顶层 message 也是中文（小程序 http 层只读 message 弹 toast）。
        // 多错时 Laravel 会拼 `(and N more errors)` —— 那是 lang/zh_CN.json 里的 JSON 翻译键，
        // 漏了它就会出现「请填写 姓名。 (and 2 more errors)」这种半中半英。
        $message = (string) $resp->json('message');
        $this->assertStringContainsString('请填写 姓名。', $message, '顶层 message 必须也是中文');
        $this->assertStringContainsString('（另 2 项错误）', $message, '多条错误的后缀不得退回英文 (and N more errors)');
        $this->assertStringNotContainsString('more errors', $message, '不得残留英文复数后缀');
    }

    /** 审批发起校验：字段中文名与类型文案（客户姓名 / 卡项名称 / 标准价 / 申请价）。 */
    public function test_approval_validation_errors_are_chinese(): void
    {
        Sanctum::actingAs($this->super());

        $errors = $this->postJson('/api/approvals', [])->assertStatus(422)->json('errors');

        $this->assertSame('请填写 客户姓名。', $errors['customerName'][0] ?? null);
        $this->assertSame('请填写 卡项名称。', $errors['cardName'][0] ?? null);
        $this->assertSame('请填写 标准价。', $errors['standardPrice'][0] ?? null);

        // enum / numeric 这类非 required 规则也要中文，而不是 "The selected ... is invalid."
        $bad = $this->postJson('/api/approvals', [
            'customerName' => 'B2客户', 'cardName' => '私教10次',
            'standardPrice' => 'abc', 'requestPrice' => '100',
            'venue' => '火星店',
        ])->assertStatus(422)->json('errors');

        $this->assertSame('标准价 必须是整数。', $bad['standardPrice'][0] ?? null);
        $this->assertSame('所选的 门店 无效。', $bad['venue'][0] ?? null);
    }

    // ==================================================== 2. AI 失败状态码

    /** 上游返回 5xx：/ai/models 必须回 502（body 结构不变）。 */
    public function test_ai_models_upstream_failure_returns_502(): void
    {
        Sanctum::actingAs($this->super());
        Http::fake(['https://8.8.8.8/v1/models' => Http::response('upstream boom', 500)]);

        $resp = $this->postJson('/api/ai/models', [
            'baseUrl' => 'https://8.8.8.8/v1',
            'apiKey' => 'sk-test',
        ]);

        $resp->assertStatus(502);
        $this->assertSame(1, $resp->json('code'), 'body 仍保持 {code:1, message}');
        $this->assertStringContainsString('获取模型列表失败', (string) $resp->json('message'));
    }

    /** 连不上上游（DNS/连接异常）：也必须 502，而不是 200 + code:1。 */
    public function test_ai_models_connection_error_returns_502(): void
    {
        Sanctum::actingAs($this->super());
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

        $resp = $this->postJson('/api/ai/models', [
            'baseUrl' => 'https://8.8.8.8/v1',
            'apiKey' => 'sk-test',
        ]);

        $resp->assertStatus(502);
        $this->assertSame(1, $resp->json('code'));
        $this->assertStringContainsString('无法连接大模型接口', (string) $resp->json('message'));
    }

    /**
     * 流式请求遇到上游 5xx：必须回 **502 JSON**，而不是 200 空流。
     *
     * 这正是 admin-web readSse 的塌陷点：200 → 按 SSE 读 → 丢弃非 `data:` 行 → LLM_EMPTY_RESPONSE。
     * 改成 502 后 readSse 的 `if (!resp.ok)` 会先把上游原文抛出来（:2311）。
     */
    public function test_ai_chat_stream_upstream_failure_returns_502_json_not_empty_stream(): void
    {
        Sanctum::actingAs($this->super());
        $this->seedAiConfig();
        Http::fake(['https://8.8.8.8/v1/chat/completions' => Http::response('upstream boom', 500)]);

        $resp = $this->postJson('/api/ai/chat', [
            'messages' => [['role' => 'user', 'content' => '你好']],
            'stream' => true,
        ]);

        $resp->assertStatus(502);
        $this->assertSame(1, $resp->json('code'));
        $this->assertStringContainsString('大模型接口返回异常', (string) $resp->json('message'));
        $this->assertStringNotContainsString(
            'text/event-stream',
            (string) $resp->headers->get('Content-Type'),
            '失败时绝不能回流，否则前端只能看到空响应'
        );
    }

    /** 上游 200 但缺少内容：同样 502（原先也是 200 + code:1）。 */
    public function test_ai_chat_missing_content_returns_502(): void
    {
        Sanctum::actingAs($this->super());
        $this->seedAiConfig();
        Http::fake(['https://8.8.8.8/v1/chat/completions' => Http::response(['choices' => []], 200)]);

        $resp = $this->postJson('/api/ai/chat', [
            'messages' => [['role' => 'user', 'content' => '你好']],
            'stream' => false,
        ]);

        $resp->assertStatus(502);
        $this->assertSame(1, $resp->json('code'));
        $this->assertStringContainsString('大模型响应缺少内容', (string) $resp->json('message'));
    }

    // ------------------------------------------------------------- 数据准备

    /** /ai/chat 需要 AppSetting.ai 里有完整配置，否则会先被 422「尚未配置 API Key」拦下。 */
    private function seedAiConfig(): void
    {
        AppSetting::create([
            'ai' => [
                'enabled' => true,
                'baseUrl' => 'https://8.8.8.8/v1',
                'apiKey' => 'sk-test',
                'model' => 'gpt-4o-mini',
                'providerLabel' => 'B2测试',
            ],
        ]);
    }

    private function super(): User
    {
        return User::factory()->create([
            'username' => uniqid('b2-super-'),
            'name' => 'B2超管',
            'role' => 'R_SUPER',
            'roles' => ['R_SUPER'],
            'status' => '启用',
            'venue' => null,
            'venues' => ['绿地店', '东部店'],
        ]);
    }
}
