<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KyBooking extends Model
{
    protected $guarded = [];

    protected $casts = [
        'start_at' => 'datetime',
        'is_trial' => 'boolean',
        'raw' => 'array',
    ];
}
