<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublishedShare extends Model
{
    protected $guarded = [];

    /**
     * 注意：`enabled` **刻意不做 boolean cast**。
     *
     * Laravel 的 boolean cast 实现是 `(bool) $value`（见
     * Illuminate\Database\Eloquent\Concerns\HasAttributes::castAttribute），
     * 而 PHP 里 `(bool) 'false' === true` —— 一旦 DB 里存的是字符串形态的
     * 'false'/'off'/'no'（驱动差异、手工改库、历史脏数据），cast 会把「已停用」
     * 静默变成启用，公开接口继续对外下发内容。
     *
     * 所以这里保留原始值，由读侧统一用 filter_var(..., FILTER_VALIDATE_BOOLEAN)
     * 做显式真值判定（见 ShareController::isEnabled / PublicShareController::enabled）：
     * 一切非真值一律 fail-closed。
     */
    protected $casts = ['payload' => 'array'];

    protected $table = 'published_shares';
}
