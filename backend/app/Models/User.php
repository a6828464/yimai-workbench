<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

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
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'venues' => 'array',
            'roles' => 'array',
            'profile' => 'array',
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
        ];
    }
}
