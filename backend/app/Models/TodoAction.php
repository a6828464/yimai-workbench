<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TodoAction extends Model
{
    protected $guarded = [];

    protected $table = 'todo_actions';

    protected $casts = ['action_date' => 'date:Y-m-d'];
}
