<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $guarded = [];

    // 全部 JSON 列都要显式 cast：漏一个就会「读出来是字符串、写进去抛 Array to string」。
    // miniapp_config（小程序到店指引）与 rules/snapshot 同级，必须在这里登记。
    protected $casts = ['rules' => 'array', 'snapshot' => 'array', 'ky' => 'array', 'ai' => 'array', 'sync_meta' => 'array', 'retention' => 'array', 'backup' => 'array', 'miniapp_config' => 'array'];

    protected $table = 'app_settings';
}
