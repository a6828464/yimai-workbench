<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 小程序「到店指引」的博客栏目（只读、免登录）。
 *
 * 数据源：官网 WordPress 的 REST API（www.nbyimai.com 与本站同机部署，
 * 用 https 直取 —— 走 localhost 需要按 SNI 指定证书，反而复杂，且 WP 站点
 * 没有暴露内部地址的必要；同机 https 回环不占公网带宽）。
 *
 * ## 为什么在后端代理而不是小程序直连 WP
 *  1. request 合法域名不用加：小程序只认 oa.nbyimai.com，WP 域名不进白名单；
 *  2. 缓存层挡在小程序与 WP 之间：栏目卡片这种高频低变内容，5 分钟缓存足够；
 *  3. WP 挂了/慢了不影响顾客页面：返回空列表，小程序端静默不渲染该栏目
 *     （与到店指引整体降级哲学一致）。
 *
 * ## 置顶口径（方案 A：WP 原生置顶）
 * 馆主在 WP 后台文章列表勾「置顶」即可；REST 的 sticky=true 原生支持。
 * 合并规则：置顶在前（按置顶列表顺序），最新文章补足剩余名额，已出现的去重。
 * limit 上限 10：这是栏目卡片不是文章库，给更大数字只会把 WP 全文拉爆。
 */
final class MiniappBlogController extends Controller
{
    /** 官网 REST 端点（生产同机；config 可覆盖，方便本地开发指向别的 WP） */
    private function wpBase(): string
    {
        return rtrim((string) config('services.wp_blog.base_url', 'https://www.nbyimai.com'), '/');
    }

    /** 列表缓存秒数：置顶/最新都属于低频变化，5 分钟足够馆主「勾个置顶就生效」的预期 */
    private const LIST_TTL = 300;

    /** 单篇缓存秒数：文章发布后不会变，给长缓存；发布后的修订也要几分钟内可见 */
    private const ITEM_TTL = 600;

    private const LIMIT_MAX = 10;

    private const LIMIT_DEFAULT = 3;

    /**
     * GET /miniapp/blog?limit=3 —— 栏目卡片数据（置顶优先 + 最新补足）。
     *
     * 响应：{ code:0, data:{ items:[ {id,title,date,excerpt,cover,link,sticky} ], source } }
     * source: live=本次真的问过 WP；cache=命中缓存；empty=WP 不可用且无缓存。
     * items 恒为数组（失败给空数组，前端按「不渲染栏目」处理，不弹错误）。
     */
    public function index()
    {
        $limit = max(1, min(self::LIMIT_MAX, (int) request()->query('limit', self::LIMIT_DEFAULT)));

        $data = Cache::remember('miniapp:blog:list:'.$limit, self::LIST_TTL, function () {
            return $this->fetchList(self::LIMIT_MAX);
        });

        // 缓存里存的是「拉满 10 条」的结果，limit 只在出口截断 ——
        // 不同 limit 共享同一份 WP 请求，避免「3 条与 5 条两个缓存各打一次 WP」。
        return ok([
            'items' => array_slice($data['items'], 0, $limit),
            'source' => $data['source'],
        ]);
    }

    /**
     * GET /miniapp/blog/{id} —— 单篇（标题 + 日期 + 全文 HTML + 封面）。
     *
     * 小程序端用 rich-text 渲染 content.rendered。图片是 WP 站的绝对 https 地址，
     * <image> 不受 request 域名白名单约束，直接可显。
     */
    public function show(int $id)
    {
        abort_unless($id > 0, 404);

        $data = Cache::remember('miniapp:blog:item:'.$id, self::ITEM_TTL, fn () => $this->fetchItem($id));

        if ($data === null) {
            // WP 拿不到（不存在或已下架）：不缓存 null（remember 语义下 null 不落缓存），
            // 直接 404 —— 文章卡片是缓存快照，点进去 404 属正常降级（文章被删）。
            abort(404, '文章不存在或已下架');
        }

        return ok($data);
    }

    // ------------------------------------------------------------------ 内部

    /**
     * 拉列表：置顶一批 + 最新一批，合并去重。
     * 返回 { items:[...最多10条], source }。任何异常 → { items:[], source:'empty' }。
     *
     * ⚠️ 实测（2026-11-08）：本站 WP 的 `_fields` 与 `_embed` **互斥** —— 请求里
     * 一旦带 `_fields`，`_embedded`（封面图）就会被裁掉。所以这里**不用 _fields**
     * 全量拉取，响应略大，但有 5 分钟缓存挡在中间，WP 与带宽都不受影响。
     */
    private function fetchList(int $take): array
    {
        try {
            // 置顶：sticky=true 只返回置顶文章（WP 原生语义）。拿满 take 条。
            $stickyRaw = $this->wpGet('?sticky=true&_embed');
            // 最新：不排除置顶（可能同时是新文），合并时去重。
            $latestRaw = $this->wpGet('?per_page='.$take.'&_embed');

            $byId = [];
            $ordered = [];
            foreach ($stickyRaw as $p) {
                $item = $this->mapPost($p, true);
                if ($item && ! isset($byId[$item['id']])) {
                    $byId[$item['id']] = true;
                    $ordered[] = $item;
                }
            }
            foreach ($latestRaw as $p) {
                $item = $this->mapPost($p, (bool) ($p['sticky'] ?? false));
                if ($item && ! isset($byId[$item['id']]) && count($ordered) < $take) {
                    $byId[$item['id']] = true;
                    $ordered[] = $item;
                }
            }

            return ['items' => array_values($ordered), 'source' => 'live'];
        } catch (\Throwable $e) {
            // WP 不可达：栏目静默消失比红屏强一万倍（顾客视角它只是个可选栏目）
            Log::warning('miniapp blog list failed: '.$e->getMessage());

            return ['items' => [], 'source' => 'empty'];
        }
    }

    private function fetchItem(int $id): ?array
    {
        try {
            // 同上：不用 _fields（会裁掉 _embedded 的封面）
            $raw = $this->wpGet('/'.$id.'?_embed');
            if (empty($raw) || ! is_array($raw) || ! isset($raw['id'])) {
                return null;
            }

            return $this->mapPostDetail($raw);
        } catch (\Throwable $e) {
            Log::warning('miniapp blog item failed: '.$e->getMessage());

            return null;
        }
    }

    /** GET WP REST，非 2xx 直接抛（由上层归一为空/404） */
    private function wpGet(string $query): array
    {
        $resp = Http::timeout(8)
            ->withHeaders(['Accept' => 'application/json'])
            ->get($this->wpBase().'/wp-json/wp/v2/posts'.$query);

        abort_if($resp->failed(), 502, 'WP 请求失败');

        $body = $resp->json();

        return is_array($body) ? $body : [];
    }

    /** WP post → 栏目卡片（无标题的脏数据直接丢） */
    private function mapPost(array $p, bool $sticky): ?array
    {
        $title = trim(strip_tags((string) ($p['title']['rendered'] ?? '')));
        if ($title === '') {
            return null;
        }

        return [
            'id' => (int) ($p['id'] ?? 0),
            'title' => $title,
            'date' => substr((string) ($p['date'] ?? ''), 0, 10),
            // 摘要：WP excerpt 是带 HTML 的（默认 55 词），剥标签去空白，超 80 字截断加省略号
            'excerpt' => $this->plainExcerpt((string) ($p['excerpt']['rendered'] ?? '')),
            'cover' => $this->coverOf($p),
            'link' => (string) ($p['link'] ?? ''),
            'sticky' => $sticky,
        ];
    }

    private function mapPostDetail(array $p): array
    {
        $mapped = $this->mapPost($p, (bool) ($p['sticky'] ?? false));

        return array_merge($mapped ?? [], [
            'content' => (string) ($p['content']['rendered'] ?? ''),
        ]);
    }

    private function coverOf(array $p): string
    {
        $media = $p['_embedded']['wp:featuredmedia'][0] ?? null;

        return is_array($media) ? (string) ($media['source_url'] ?? '') : '';
    }

    private function plainExcerpt(string $html): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
        if (mb_strlen($text) <= 80) {
            return $text;
        }

        return mb_substr($text, 0, 80).'…';
    }
}
