<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingPost extends Model
{
    protected $fillable = ['user_id', 'platform', 'title', 'content', 'reply', 'source'];
}
