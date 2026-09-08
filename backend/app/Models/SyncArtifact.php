<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncArtifact extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'row_count' => 'integer',
            'size' => 'integer',
            'date_from' => 'date',
            'date_to' => 'date',
            'is_full' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function syncJob(): BelongsTo
    {
        return $this->belongsTo(SyncJob::class);
    }
}
