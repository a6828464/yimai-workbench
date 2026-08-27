<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $guarded = [];
    protected $casts = ['renewal_plan' => 'array', 'decline' => 'array', 'needs_help' => 'boolean', 'in_revive' => 'boolean'];
    protected $table = 'customers';
}
