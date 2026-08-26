<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $guarded = [];
    protected $table = 'audit_logs';
    /** 表结构使用 time 列（useCurrent）记录写入时间，非 Laravel 时间戳 */
    public $timestamps = false;
}