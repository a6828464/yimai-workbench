<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 营销工具收藏（按 user_id 收口，跨设备同步）。
 *
 * 与 MarketingPost（生成历史）的分工：历史可再生成、按 platform 裁剪；收藏不可再生、
 * 超限拒绝新增。两张表不共用、不互挤（见迁移里的说明）。
 *
 * `user_id` 在 $fillable 里是因为 create() 要写它，但它**只由服务端从登录态赋值**，
 * 绝不来自请求体（见 MarketingFavoriteController::store）。
 */
final class MarketingFavorite extends Model
{
    protected $fillable = ['user_id', 'platform', 'title', 'content', 'reply', 'cover_text', 'tags'];

    protected $casts = ['tags' => 'array'];
}
