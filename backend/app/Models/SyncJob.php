<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyncJob extends Model
{
    protected $guarded = [];

    protected $table = 'sync_jobs';

    protected function casts(): array
    {
        return [
            'date_range' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(SyncArtifact::class);
    }
}
