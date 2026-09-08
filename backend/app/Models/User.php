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
            'profile' => 'array',
        ];
    }

    public function userInfo(): array
    {
        return [
            'userId' => $this->id,
            'userName' => $this->nickname ?: $this->name,
            'roles' => [$this->role],
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
