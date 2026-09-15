<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KyBooking extends Model
{
    /** 课型中文标签 */
    public const KIND_LABELS = [
        'private' => '私教',
        'small' => '小班',
        'group' => '团课',
    ];

    protected $guarded = [];

    protected $casts = [
        'start_at' => 'datetime',
        'is_trial' => 'boolean',
        'raw' => 'array',
    ];

    /** 课型：private=私教，small=小班（精品课），group=团课（精品团课） */
    public function courseKind(): string
    {
        if ((string) $this->course_kind !== '') {
            return (string) $this->course_kind;
        }

        // 兜底口径与 2026_09_15_000001 迁移、KyMemberSyncService::bookingFact 一致
        return match ((string) ($this->raw['course_type'] ?? '')) {
            '2' => 'private',
            '3' => 'small',
            '1' => 'group',
            default => (string) $this->booking_type === '团课' ? 'group' : 'private',
        };
    }
}
