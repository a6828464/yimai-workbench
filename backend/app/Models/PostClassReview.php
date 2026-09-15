<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostClassReview extends Model
{
    protected $guarded = [];

    protected $casts = [
        'class_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'red_flag' => 'boolean',
        'payload' => 'array',
        'share' => 'array',
    ];
}
