<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublishedShare extends Model
{
    protected $guarded = [];
    protected $casts = ['payload' => 'array'];
    protected $table = 'published_shares';
}
