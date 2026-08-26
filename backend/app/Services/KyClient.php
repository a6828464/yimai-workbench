<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * KeepYoga 只读客户端（阶段1：凭据迁移到服务端）
 * - 浏览器不再接触 access_token，统一由后端代理转发
 * - 路径白名单防止任意上游调用
 * - token 进程外缓存复用；errno=6（登录态失效）自动重登重试一次
 */
class KyClient
{
    public const BASE = 'https://cloud.keepyoga.com';
    public const BRAND_ID = '108193';
    public const VERSION = '10.1.3';

    /** 允许代理的上游接口白名单 */
    public const ALLOWED_PATHS = [
        'member/api/getmembersbycondwithpager',
        'member/api/getvisitors',
        'mcard/api/getmcardsbycond',
        'venue/api/getallcontractlist',
        'course/api/queryreversionleague',
    ];

    private const CACHE_KEY = 'ky_access_token';

    /** 凭据来源优先级：数据库 app_settings.ky（后台可改）> .env */
    public static function credentials(): array
    {
        $ky = \App\Models\AppSetting::first()?->ky ?? [];
        $phone = is_string($ky['phone'] ?? null) && $ky['phone'] !== '' ? (string) $ky['phone'] : (string) config('services.ky.phone');
        $password = is_string($ky['password'] ?? null) && $ky['password'] !== '' ? (string) $ky['password'] : (string) config('services.ky.password');

        return [$phone, $password];
    }

    public static function configured(): bool
    {
        [$phone, $password] = self::credentials();

        return (bool) ($phone && $password);
    }

    /** 登录并返回 access_token（默认走缓存） */
    public static function token(bool $force = false): string
    {
        if (! self::configured()) {
            throw new RuntimeException('缺少 KY_PHONE/KY_PASSWORD 配置');
        }
        if (! $force && Cache::has(self::CACHE_KEY)) {
            return (string) Cache::get(self::CACHE_KEY);
        }

        [$phone, $password] = self::credentials();
        $resp = Http::asForm()->timeout(30)->post(self::BASE.'/passport/api/login', [
            'phone' => $phone,
            'pwd' => md5($password),
            'keep' => '1',
            'brand_id' => '',
            'venue_id' => '',
        ])->json();

        $token = (string) ($resp['data']['access_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('KeepYoga 登录失败');
        }
        Cache::put(self::CACHE_KEY, $token, now()->addHours(2));

        return $token;
    }

    /**
     * 转发上游 POST 表单接口
     *
     * @param  array<string, mixed>  $form  业务参数（venue_id 等由调用方携带）
     * @return array<string, mixed> 上游原始 JSON
     */
    public static function call(string $path, array $form): array
    {
        if (! in_array($path, self::ALLOWED_PATHS, true)) {
            throw new RuntimeException("路径不在白名单: {$path}");
        }

        $lastErrno = '';
        foreach ([false, true] as $forceRelogin) {
            $payload = $form + [
                'access_token' => self::token($forceRelogin),
                'brand_id' => self::BRAND_ID,
                'version' => self::VERSION,
                'os' => 'pc',
            ];
            $resp = Http::asForm()->timeout(30)->post(self::BASE.'/'.$path, $payload)->json();
            $errno = (string) ($resp['errno'] ?? '0');
            if ($errno === '0') {
                return $resp;
            }
            if ($errno !== '6') {
                throw new RuntimeException("{$path} errno={$errno} ".($resp['emsg'] ?? ''));
            }
            $lastErrno = $errno;
        }
        throw new RuntimeException("{$path} 登录态失效(errno={$lastErrno})");
    }
}
