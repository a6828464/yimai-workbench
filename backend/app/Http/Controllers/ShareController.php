<?php

namespace App\Http\Controllers;

use App\Models\PublishedShare;
use Illuminate\Http\Request;

final class ShareController extends Controller
{
    /** POST /shares/publish */
    public function publish(Request $r)
    {
        $d = $r->validate([
            'type' => 'required|string|in:sales,training',
            'token' => ['required', 'string', 'min:4', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'payload' => 'required|array',
        ]);
        abort_if(
            strlen((string) json_encode($d['payload'], JSON_UNESCAPED_UNICODE)) > 200000,
            422, '分享内容过大，请精简后重试'
        );
        // 归属校验：已有同 token 分享非本人创建时，仅超管可覆盖，防止劫持他人对外 H5
        $existing = PublishedShare::where('type', $d['type'])->where('token', $d['token'])->first();
        abort_if(
            $existing && $existing->created_by !== $r->user()->name && $r->user()->role !== 'R_SUPER',
            403, '无权覆盖他人创建的分享'
        );
        PublishedShare::updateOrCreate(
            ['type' => $d['type'], 'token' => $d['token']],
            ['payload' => $d['payload'], 'created_by' => $r->user()->name]
        );
        audit($r, '发布', 'H5分享', 0, "分享码[{$d['token']}]", '双店', "类型：{$d['type']}");

        return ok(['ok' => true]);
    }
}
