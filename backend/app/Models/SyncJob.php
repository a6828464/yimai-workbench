<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncJob extends Model
{
    protected $guarded = [];
    protected $casts = ['finished_at' => 'datetime'];
    protected $table = 'sync_jobs';
}
