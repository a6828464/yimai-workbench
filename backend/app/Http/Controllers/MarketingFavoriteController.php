<?php

namespace App\Http\Controllers;

use App\Models\MarketingFavorite;
use Illuminate\Http\Request;

/**
 * 营销工具收藏（按账号持久化）。
 *
 * 规格：docs/营销工具/06-收藏服务端持久化-设计规格.md §2（含 §2.4 的 user_id 收口红线）。
 * 与生成历史（MarketingController）的分工：历史超限静默删旧（可再生成），收藏超限**拒绝新增**——
 * 静默删掉用户第 301 条收藏，正是 O-14「用户攒的东西没了」在服务端的同型复现。
 */
final class MarketingFavoriteController extends Controller
{
    /**
     * 收藏条数上限（该 user 全平台合计）。写入判定与 GET 读上限**共用同一个值**：
     * 两个数字若不一致，会出现「存得进、看不到」，用户无法自证收藏是否成功。
     */
    public const MARKETING_FAVORITE_LIMIT = 300;

    /** GET /marketing/favorites */
    public function index(Request $r)
    {
        $q = MarketingFavorite::query()->where('user_id', $r->user()->id)->latest('id');
        // platform 只做真值判断、不做 in: 校验（与 /marketing/history 一致）：传非法值自然返回空数组
        if ($p = $r->input('platform')) {
            $q->where('platform', (string) $p);
        }

        return ok($q->limit(self::MARKETING_FAVORITE_LIMIT)->get()->map(fn ($f) => [
            'id' => $f->id,
            'platform' => $f->platform,
            'title' => $f->title,
            'content' => $f->content,
            'reply' => $f->reply,
            'coverText' => $f->cover_text,
            // tags 列可能为 null（老行/异常行），必须归一为数组：前端拿到 null 会崩在 .map/.length
            'tags' => array_values((array) $f->tags),
            'createdAt' => optional($f->created_at)->format('Y-m-d H:i'), // 与历史同格式
        ]));
    }

    /** POST /marketing/favorites */
    public function store(Request $r)
    {
        // ⚠️ user_id 刻意**不在**规则里：请求体传了也既不校验也不使用（validate() 只返回白名单字段，
        // 它不可能进 create()）。报错会把「服务端不接受 user_id」暴露成可探测信号，故静默忽略。
        $d = $r->validate([
            'platform' => 'required|string|in:朋友圈,小红书',
            'title' => 'nullable|string|max:120',
            'content' => 'required|string|max:20000',
            'reply' => 'nullable|string|max:3000',
            'coverText' => 'nullable|string|max:3000',
            'tags' => 'nullable|array|max:10',
            'tags.*' => 'string|max:60',
        ], [
            // 非法枚举把允许值原样列出（措辞对齐 app/Support/helpers.php 的 leadEnumMessage）：
            // 默认的 "The selected platform is invalid." 对用户零信息量。
            'platform.in' => 'platform 取值不在允许范围内，允许值为：朋友圈、小红书',
        ]);

        $userId = $r->user()->id;
        // fail-closed：超限拒绝新增，不删任何既有收藏（用户自己决定删哪条）。
        // 竞态说明：上限检查与插入之间不加锁（与 saveHistory 的裁剪同样裸查询，口径一致）；
        // 上限是产品约束而非安全边界，并发双写可能得到 301 条。
        if (MarketingFavorite::where('user_id', $userId)->count() >= self::MARKETING_FAVORITE_LIMIT) {
            abort(422, sprintf('收藏已达上限 %d 条，请先删除部分收藏再添加', self::MARKETING_FAVORITE_LIMIT));
        }

        $f = MarketingFavorite::create([
            'user_id' => $userId,
            'platform' => $d['platform'],
            'title' => mb_substr((string) ($d['title'] ?? ''), 0, 120),
            'content' => $d['content'],
            'reply' => $d['reply'] ?? null,
            'cover_text' => $d['coverText'] ?? null,
            'tags' => array_values(array_unique(array_map(
                fn ($t) => mb_substr((string) $t, 0, 60),
                $d['tags'] ?? []
            ))),
        ]);

        return ok(['id' => $f->id]);
    }

    /** DELETE /marketing/favorites/{id} */
    public function destroy(Request $r, int $id)
    {
        // 两个条件都要有：只写 where('id') 就是越权删。
        // 「他人 id」与「不存在 id」都返回 ok 且什么都不做 —— 有意的防枚举设计：
        // 若「存在但不是你的」与「不存在」的状态码不同，即可用它枚举他人收藏 id 的存在性。
        // 红线：不得改成「找不到就 404」（与 MarketingController::deleteHistory 同语义）。
        MarketingFavorite::where('user_id', $r->user()->id)->where('id', $id)->delete();

        return ok(['ok' => true]);
    }
}
