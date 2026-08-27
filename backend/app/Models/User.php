<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    protected $fillable = [
        'name',
        'email',
        'password',
        'username',
        'role',
        'venue',
        'venues',
        'status',
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
        ];
    }

    public function userInfo(): array
    {
        return [
            'userId' => $this->id,
            'userName' => $this->name,
            'roles' => [$this->role],
            'venue' => $this->venue,
            'venues' => $this->venues ?? [],
            'buttons' => [],
            'email' => $this->email,
            'status' => $this->status ?? '启用',
        ];
    }
}
