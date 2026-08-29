<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RenewalEvaluation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'answers' => 'array',
        'evaluated_at' => 'datetime',
    ];
}
