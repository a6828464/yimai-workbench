<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    /**
     * 人工维护的会员经营状态（唯一来源：控制器校验、前端下拉都读这里）。
     *
     * 与另外三个字段的分工（别再混用）：
     *  - `layer`（P0-P5）是**算出来的**经营分层，随同步重算，不可手改；
     *  - `status` 是档案完善度（跟进中/待完善），来自同步占位；
     *  - `in_revive` 只是「待复活」这一个开关，供五清单判定用；
     *  - 本枚举是**人说了算**的经营状态，供列表/详情一眼识别与筛选。
     */
    public const MEMBER_STATES = ['正常', '待续费', '待复活', '已暂停', '已流失'];

    protected $guarded = [];

    protected $casts = ['renewal_plan' => 'array', 'decline' => 'array', 'card_stats' => 'array', 'cards_list' => 'array', 'needs_help' => 'boolean', 'in_revive' => 'boolean', 'eval_at' => 'date', 'enrolled_at' => 'date', 'visit_at' => 'date'];

    protected $table = 'customers';

    public function renewalEvaluations()
    {
        return $this->hasMany(RenewalEvaluation::class);
    }
}
