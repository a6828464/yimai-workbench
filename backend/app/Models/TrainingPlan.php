<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrainingPlan extends Model
{
    protected $guarded = [];
    protected $casts = ['profile' => 'array', 'goal' => 'array', 'content' => 'array', 'images' => 'array', 'share' => 'array'];
    protected $table = 'training_plans';
}