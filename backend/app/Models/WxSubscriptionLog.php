<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 微信订阅消息下发流水（只增不改）。
 *
 * status 三态与建表迁移一致：
 *  - sent    微信已受理（errcode=0）
 *  - skipped 本地就不该发：模板未配置 / 账号未绑微信 / 剩余次数为 0
 *  - failed  微信侧报错（HTTP 异常、errcode≠0、拿不到 access_token）
 *
 * payload 存下发请求体与微信响应，**不含** access_token / appsecret。
 */
final class WxSubscriptionLog extends Model
{
    public const STATUS_SENT = 'sent';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'template_key',
        'status',
        'errcode',
        'errmsg',
        'payload',
    ];

    protected $casts = [
        'errcode' => 'integer',
        'payload' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
