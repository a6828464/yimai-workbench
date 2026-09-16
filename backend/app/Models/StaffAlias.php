<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 人员别名：把业务表里出现过的姓名映射回账号
 *
 * 归属类字段存的是姓名字符串而非外键，姓名又是会变的（改过名、随心瑜登记不一致、
 * 历史数据用了 nickname）。这张表记录"一个人还可能被写成哪些名字"，
 * 判断归属时连同 `users.name` 一起算（见 helpers.php 的 `staffNames()`）。
 */
class StaffAlias extends Model
{
    protected $guarded = [];

    protected $table = 'staff_aliases';

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
