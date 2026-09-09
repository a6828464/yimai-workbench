<?php

namespace App\Http\Controllers;

use App\Models\MarketingPost;
use Illuminate\Http\Request;

final class MarketingController extends Controller
{
    /** GET /marketing/history */
    public function history(Request $r)
    {
        $q = MarketingPost::query()->where('user_id', $r->user()->id)->latest('id');
        if ($p = $r->input('platform')) {
            $q->where('platform', (string) $p);
        }

        return ok($q->limit(300)->get()->map(fn ($p) => [
            'id' => $p->id,
            'platform' => $p->platform,
            'title' => $p->title,
            'content' => $p->content,
            'reply' => $p->reply,
            'source' => $p->source,
            'createdAt' => optional($p->created_at)->format('Y-m-d H:i'),
        ]));
    }

    /** POST /marketing/history */
    public function saveHistory(Request $r)
    {
        $d = $r->validate([
            'platform' => 'required|string|in:朋友圈,小红书',
            'title' => 'nullable|string|max:120',
            'content' => 'required|string|max:20000',
            'reply' => 'nullable|string|max:3000',
            'source' => 'nullable|string|in:llm,fallback',
        ]);
        $p = MarketingPost::create([
            'user_id' => $r->user()->id,
            'platform' => $d['platform'],
            'title' => mb_substr((string) ($d['title'] ?? ''), 0, 120),
            'content' => $d['content'],
            'reply' => $d['reply'] ?? null,
            'source' => $d['source'] ?? 'llm',
        ]);
        $stale = MarketingPost::where('user_id', $r->user()->id)->where('platform', $d['platform'])
            ->orderByDesc('id')->skip(300)->take(50)->pluck('id');
        if ($stale->isNotEmpty()) {
            MarketingPost::whereIn('id', $stale)->delete();
        }

        return ok(['id' => $p->id]);
    }

    /** DELETE /marketing/history/{id} */
    public function deleteHistory(Request $r, int $id)
    {
        MarketingPost::where('user_id', $r->user()->id)->where('id', $id)->delete();

        return ok(['ok' => true]);
    }
}
