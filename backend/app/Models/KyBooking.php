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

    /**
     * 课型：private=私教，small=小班（精品课），group=团课（精品团课）。
     *
     * 列值优先（同步写入的权威结果，含迁移回填的历史行），列值为空时退回
     * `raw.course_type`。判定口径统一在 `courseKindFrom()`，本方法不再自带一份
     * match —— 原来这里按 `booking_type` 兜底成 private，与今日预约出口（兜底成
     * 团课）方向相反，同一行数据在不同出口会显示不同课型。
     *
     * 各出口一律用本方法取课型，不要各自解析 `raw`：`raw` 的形态不保证
     * （cast 后是数组，但直接 DB 取值、`select` 部分列时可能是 JSON 串）。
     */
    public function courseKind(): string
    {
        $column = (string) $this->course_kind;
        if ($column !== '') {
            return $column;
        }

        $raw = $this->raw;
        if (! is_array($raw)) {
            $raw = (array) json_decode((string) $raw, true);
        }

        return courseKindFrom(isset($raw['course_type']) ? (string) $raw['course_type'] : null);
    }
}
