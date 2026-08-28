<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    protected $guarded = [];
    protected $table = 'leads';

    protected $casts = [
        'trial_cards' => 'array',
        'coupon_total' => 'integer',
        'coupon_remaining' => 'integer',
    ];
}