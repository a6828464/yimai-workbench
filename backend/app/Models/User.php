<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\StaffDirectory;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * 账号变动即失效「名字 → 账号」映射。
     *
     * `users.name` 是归属判断的权威名之一，新建/改名/改昵称/删除都会改变解析结果：
     *  - 新建账号：同名历史数据从此有主（`backfillStaffOwnerIds()` 才认得出）；
     *  - 改昵称：昵称也算一个可用名字（见 `StaffDirectory::namesFor()`）；
     *  - 删除账号：名字要从池里消失，否则旧数据会被判成"有主"却指向不存在的 id。
     *
     * 不做这件事的实际后果：管理员刚建好账号，同一请求内后续的归属判断仍按旧映射走，
     * 页面看起来"建了但没生效"，只能靠刷新解决 —— 属于最难复现的一类缺陷。
     */
    protected static function booted(): void
    {
        $flush = static fn () => app(StaffDirectory::class)->flush();

        static::saved($flush);
        static::deleted($flush);
    }

    protected $fillable = [
        'name',
        'nickname',
        'email',
        'password',
        'username',
        'role',
        'roles',
        'venue',
        'venues',
        'status',
        'phone',
        'avatar',
        'profile',
        // 人员列表的手动顺序（拖动排序）。可批量赋值是必要的：开通账号时就要带上
        // 「接在末尾」的编号；不放 fillable 的话 User::create 会静默丢掉这个字段，
        // 新账号会以默认 0 跳到列表最前面（与「新人在末尾」的预期相反）。
        'sort_order',
        // 微信绑定列：只允许通过绑定接口写入，不放进 $fillable 是有意的 ——
        // 放进 fillable 后任何 User::create($request->all()) 都能自封 openid，
        // 等于伪造微信身份登录。绑定走 forceFill + 唯一索引兜底。
    ];

    protected $hidden = [
        'password',
        'remember_token',
        // 微信内部标识不外泄：userInfo() 也不返回，避免前端或日志带走可被冒用的键。
        'wx_openid',
        'wx_unionid',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'venues' => 'array',
            'roles' => 'array',
            'profile' => 'array',
            'wx_bound_at' => 'datetime',
        ];
    }

    public function userInfo(): array
    {
        return [
            'userId' => $this->id,
            // userName 是**展示名**（优先昵称），只用于界面呈现
            'userName' => $this->nickname ?: $this->name,
            // staffName 是**归属用的规范名**，与后端归属列（service_teacher / owner /
            // consultant / teacher_name / created_by）存的值同源。凡是"写入或比较归属"
            // 都必须用它 —— 用 userName 会把昵称写进归属列，而后端按规范名过滤，
            // 结果是这条数据对本人消失。
            'staffName' => (string) $this->name,
            'roles' => userRoles($this),
            'venue' => $this->venue,
            'venues' => $this->venues ?? [],
            'buttons' => [],
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar' => $this->avatar,
            'status' => $this->status ?? '启用',
            // 微信绑定态。openid 仍然藏在 $hidden 里（第 39 行口径不变），这里只给
            // 「有没有绑、绑在什么时候」，因为只有员工本人能看到自己的 /me：
            //  - 未绑 → 小程序「我的」页显示自己的 openid 报给管理员；
            //  - 已绑 → 显示绑定时间，避免「到底绑没绑」靠猜。
            'wxBound' => ! empty($this->wx_openid),
            'wxBoundAt' => $this->wx_bound_at?->toIso8601String(),
        ];
    }
}
