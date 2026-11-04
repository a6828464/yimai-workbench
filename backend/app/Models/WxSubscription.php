<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 微信订阅消息授权账本（一个账号 × 一个模板键 = 一行）。
 *
 * 订阅消息是**一次性**授权：用户点一次「允许」= 一次下发机会，微信侧没有查询接口，
 * 所以「还能发几条」只能靠这张表记账（详见建表迁移的注释）。
 *
 * `user_id` 在 $fillable 里是因为 updateOrCreate() 要写它，但它**只由服务端从登录态取值**，
 * 绝不来自请求体（控制器里一律用 `$r->user()->id`）。
 */
final class WxSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'template_key',
        'remain',
        'total_accepted',
        'last_accepted_at',
        'last_sent_at',
    ];

    protected $casts = [
        'remain' => 'integer',
        'total_accepted' => 'integer',
        'last_accepted_at' => 'datetime',
        'last_sent_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
