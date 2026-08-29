<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $guarded = [];

    protected $table = 'tasks';

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
