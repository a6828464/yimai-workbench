<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $guarded = [];
    protected $casts = ['rules' => 'array', 'snapshot' => 'array'];
    protected $table = 'app_settings';
}