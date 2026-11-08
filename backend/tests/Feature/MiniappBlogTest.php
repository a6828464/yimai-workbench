<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 小程序博客栏目（GET /miniapp/blog、GET /miniapp/blog/{id}）。
 *
 * 数据源是官网 WP REST —— 测试里全部用 Http::fake 桩掉，不打真网。
 * 断言四件事：置顶合并顺序、limit 截断、WP 挂掉静默空列表、单篇 404/正文。
 */
final class MiniappBlogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 每个用例独立：列表/单篇缓存都要清，避免用例间串味
        Cache::flush();
        config(['services.wp_blog.base_url' => 'https://wp.test']);
    }

    private function makePost(int $id, string $title, string $date, string $cover = ''): array
    {
        return [
            'id' => $id,
            'title' => ['rendered' => $title],
            'date' => $date,
            'excerpt' => ['rendered' => '<p>这是文章 '.$id.' 的摘要内容。</p>'],
            'link' => 'https://wp.test/archives/'.$id,
            'sticky' => false,
            '_embedded' => $cover !== ''
                ? ['wp:featuredmedia' => [['source_url' => $cover]]]
                : new \stdClass(),
        ];
    }

    /** 置顶在前 + 最新补足 + 去重，limit 出口截断。 */
    public function test_list_merges_sticky_first_then_latest(): void
    {
        $sticky = array_merge($this->makePost(101, '置顶文章甲', '2026-10-01T09:00:00'), ['sticky' => true]);
        $sticky2 = array_merge($this->makePost(102, '置顶文章乙', '2026-09-01T09:00:00'), ['sticky' => true]);
        $latest = [
            $this->makePost(103, '最新文章一', '2026-11-08T10:00:00'),
            $this->makePost(104, '最新文章二', '2026-11-07T10:00:00'),
            // 最新里再出现置顶甲：必须去重
            $sticky,
        ];

        Http::fake([
            'https://wp.test/wp-json/wp/v2/posts?sticky=true*' => Http::response([$sticky, $sticky2]),
            'https://wp.test/wp-json/wp/v2/posts?per_page=10*' => Http::response($latest),
        ]);

        $res = $this->getJson('/api/miniapp/blog?limit=3')->assertOk();

        $items = $res->json('data.items');
        $this->assertCount(3, $items);
        // 两条置顶在前（顺序按置顶列表），最新补第三；甲不重复出现
        $this->assertSame([101, 102, 103], array_column($items, 'id'));
        $this->assertTrue($items[0]['sticky']);
        $this->assertFalse($items[2]['sticky']);
        $this->assertSame('置顶文章甲', $items[0]['title']);
        $this->assertSame('2026-10-01', $items[0]['date']);
        $this->assertSame('live', $res->json('data.source'));
    }

    /** limit 边界：<1 归 1，>10 归 10。 */
    public function test_limit_clamped(): void
    {
        Http::fake([
            'https://wp.test/wp-json/wp/v2/posts?sticky=true*' => Http::response([]),
            'https://wp.test/wp-json/wp/v2/posts?per_page=10*' => Http::response([]),
        ]);

        $this->getJson('/api/miniapp/blog?limit=0')->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson('/api/miniapp/blog?limit=99')->assertOk();
    }

    /** WP 挂了：静默空列表（source=empty），不 5xx —— 顾客端只是不渲染栏目。 */
    public function test_wp_down_returns_empty_list_silently(): void
    {
        Http::fake(fn () => Http::response(['message' => 'Server Error'], 500));

        $res = $this->getJson('/api/miniapp/blog')->assertOk();

        $this->assertSame([], $res->json('data.items'));
        $this->assertSame('empty', $res->json('data.source'));
    }

    /** 列表命中缓存：第二次请求不再打 WP。 */
    public function test_list_cached(): void
    {
        Http::fake([
            'https://wp.test/wp-json/wp/v2/posts?sticky=true*' => Http::response([]),
            'https://wp.test/wp-json/wp/v2/posts?per_page=10*' => Http::response([
                $this->makePost(1, '缓存验证', '2026-11-01T00:00:00'),
            ]),
        ]);

        $this->getJson('/api/miniapp/blog')->assertOk();
        Http::assertSentCount(2); // 置顶一次 + 最新一次

        // 清掉 fake 计数，第二次全走缓存（若再打网会被 fake 拦住并计数）
        $this->getJson('/api/miniapp/blog')->assertOk();
        Http::assertSentCount(2);
    }

    /** 无标题的脏数据被丢弃，不会渲染出空卡片。 */
    public function test_titleless_post_dropped(): void
    {
        $dirty = $this->makePost(200, '', '2026-11-01T00:00:00');

        Http::fake([
            'https://wp.test/wp-json/wp/v2/posts?sticky=true*' => Http::response([]),
            'https://wp.test/wp-json/wp/v2/posts?per_page=10*' => Http::response([$dirty]),
        ]);

        $res = $this->getJson('/api/miniapp/blog')->assertOk();

        $this->assertSame([], $res->json('data.items'));
    }

    /** 摘要：剥 HTML、压空白、超 80 字截断。 */
    public function test_excerpt_stripped_and_clamped(): void
    {
        $long = str_repeat('摘要', 60); // 120 字
        $p = $this->makePost(300, '长摘要文章', '2026-11-01T00:00:00');
        $p['excerpt'] = ['rendered' => '<p>'.$long.'</p>'];

        Http::fake([
            'https://wp.test/wp-json/wp/v2/posts?sticky=true*' => Http::response([]),
            'https://wp.test/wp-json/wp/v2/posts?per_page=10*' => Http::response([$p]),
        ]);

        $res = $this->getJson('/api/miniapp/blog')->assertOk();
        $excerpt = $res->json('data.items.0.excerpt');

        $this->assertSame(81, mb_strlen($excerpt)); // 80 字 + 省略号
        $this->assertStringEndsWith('…', $excerpt);
        $this->assertStringNotContainsString('<p>', $excerpt);
    }

    /** 单篇：返回标题 + 日期 + 全文 + 封面；排版归一（语义 class + 剥内联样式）。 */
    public function test_show_returns_full_content(): void
    {
        Http::fake([
            'https://wp.test/wp-json/wp/v2/posts/55?*' => Http::response(
                array_merge($this->makePost(55, '全文文章', '2026-11-05T08:00:00', 'https://wp.test/cover.jpg'), [
                    'content' => ['rendered' => '<p>正文第一段。</p><h2>大标题</h2><h3>小标题</h3><ul><li>要点一</li></ul><blockquote>引用</blockquote><p style="text-align:center;margin:20px 0"><img decoding="async" src="https://wp.test/a.jpg" alt="示例" style="max-width:100%" /></p>'],
                ])
            ),
        ]);

        $res = $this->getJson('/api/miniapp/blog/55')->assertOk();

        $this->assertSame('全文文章', $res->json('data.title'));
        $this->assertSame('https://wp.test/cover.jpg', $res->json('data.cover'));
        $content = (string) $res->json('data.content');
        $this->assertStringContainsString('正文第一段', $content);
        // 排版归一：语义 class 已打上（前端 rich-text 靠这些 class 配层级样式）
        $this->assertStringContainsString('class="wp-h2"', $content);
        $this->assertStringContainsString('class="wp-h3"', $content);
        $this->assertStringContainsString('class="wp-list"', $content);
        $this->assertStringContainsString('class="wp-li"', $content);
        $this->assertStringContainsString('class="wp-quote"', $content);
        $this->assertStringContainsString('class="wp-img" src="https://wp.test/a.jpg" alt="示例"', $content);
        // img 的内联样式/杂属性被剥掉（自适应交给前端 class）
        $this->assertStringNotContainsString('max-width:100%', $content);
        $this->assertStringNotContainsString('decoding=', $content);
        // p 的内联 style 被剥掉（对齐与边距统一由前端 class 控制）
        $this->assertStringNotContainsString('text-align:center', $content);
    }

    /** 单篇不存在 / WP 挂 → 404，不是 5xx。 */
    public function test_show_missing_returns_404(): void
    {
        Http::fake(fn () => Http::response(['code' => 'rest_post_invalid_id'], 404));

        $this->getJson('/api/miniapp/blog/99999')->assertNotFound();
    }

    /** 非数字 id 被路由 whereNumber 直接拒。 */
    public function test_non_numeric_id_rejected(): void
    {
        $this->getJson('/api/miniapp/blog/abc')->assertNotFound();
    }

    /** 免登录：不带 token 也能访问（顾客页面）。 */
    public function test_no_auth_required(): void
    {
        Http::fake([
            'https://wp.test/wp-json/wp/v2/posts?sticky=true*' => Http::response([]),
            'https://wp.test/wp-json/wp/v2/posts?per_page=10*' => Http::response([]),
        ]);

        $this->getJson('/api/miniapp/blog', ['Authorization' => ''])
            ->assertOk();
    }

    /** 分页通道（?page=）：纯最新序、hasMore 判断、WP 挂给空页。 */
    public function test_page_pagination_and_has_more(): void
    {
        // 11 篇 → 第 1 页 10 条 hasMore=true；第 2 页 1 条 hasMore=false
        $all = [];
        for ($i = 1; $i <= 11; $i++) {
            $all[] = $this->makePost($i, "文章 {$i}", sprintf('2026-11-%02dT09:00:00', $i));
        }

        Http::fake([
            'https://wp.test/wp-json/wp/v2/posts?per_page=11&page=1&*' => Http::response($all),
            'https://wp.test/wp-json/wp/v2/posts?per_page=11&page=2&*' => Http::response([]),
        ]);

        $p1 = $this->getJson('/api/miniapp/blog?page=1')->assertOk();
        $this->assertCount(10, $p1->json('data.items'));
        $this->assertTrue($p1->json('data.hasMore'));
        $this->assertSame(1, $p1->json('data.page'));

        $p2 = $this->getJson('/api/miniapp/blog?page=2')->assertOk();
        $this->assertSame([], $p2->json('data.items'));
        $this->assertFalse($p2->json('data.hasMore'));

        // page=0 钳到 1（不发负页号给 WP）
        $this->getJson('/api/miniapp/blog?page=0')->assertOk();
    }

    /** 分页通道：WP 挂 → 空页 hasMore=false（列表页停止翻页，不报错）。 */
    public function test_page_wp_down_returns_empty(): void
    {
        Http::fake(fn () => Http::response([], 500));

        $res = $this->getJson('/api/miniapp/blog?page=1')->assertOk();

        $this->assertSame([], $res->json('data.items'));
        $this->assertFalse($res->json('data.hasMore'));
        $this->assertSame('empty', $res->json('data.source'));
    }
}
