<?php

namespace App\Models;

use App\Services\StaffDirectory;
use Illuminate\Database\Eloquent\Model;

/**
 * 人员别名：把业务表里出现过的姓名映射回账号
 *
 * 归属类字段存的是姓名字符串而非外键，姓名又是会变的（改过名、随心瑜登记不一致、
 * 历史数据用了 nickname）。这张表记录"一个人还可能被写成哪些名字"，
 * 判断归属时连同 `users.name` 一起算（见 helpers.php 的 `staffNames()`）。
 *
 * ## 为什么模型事件里要 flush 身份中心
 *
 * `StaffDirectory` 会把「名字 → 账号」的全表映射缓存到请求结束（会员列表一次请求要
 * 判断几十次归属，不缓存就是几十次全表扫描）。而这张表**就是**那份映射的数据源：
 * 任何一行变动（管理员保存别名、合并账号、测试里直接 create）都必须让缓存立刻失效，
 * 否则同一请求内会出现"刚认领完，列表还是看不到"的假象。
 *
 * 放在模型事件而不是控制器里：写入点不止一处（控制器、服务、迁移、工厂），靠调用方
 * 记得 flush 必然会漏 —— 而漏掉的表现是"要刷新一次才生效"，最难复现。
 */
class StaffAlias extends Model
{
    protected $guarded = [];

    protected $table = 'staff_aliases';

    protected static function booted(): void
    {
        $flush = static fn () => app(StaffDirectory::class)->flush();

        static::saved($flush);
        static::deleted($flush);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
