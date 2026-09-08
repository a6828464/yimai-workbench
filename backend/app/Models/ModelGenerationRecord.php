<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModelGenerationRecord extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }
}
