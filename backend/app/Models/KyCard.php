<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KyCard extends Model
{
    protected $guarded = [];

    protected $casts = [
        'deal_price' => 'float',
        'price' => 'float',
        'is_taste' => 'boolean',
    ];
}
