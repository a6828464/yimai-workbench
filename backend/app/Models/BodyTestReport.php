<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BodyTestReport extends Model
{
    protected $guarded = [];

    protected $casts = [
        'tested_at' => 'datetime',
        'score' => 'float',
        'profile' => 'array',
        'composition' => 'array',
        'abnormal' => 'array',
        'posture' => 'array',
        'observations' => 'array',
        'directions' => 'array',
        'health' => 'array',
        'red_flags' => 'array',
        'raw' => 'array',
    ];
}
