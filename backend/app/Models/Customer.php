<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $guarded = [];
    protected $casts = ['renewal_plan' => 'array', 'decline' => 'array'];
    protected $table = 'customers';
}