<?php

namespace App\Providers;

use App\Services\StaffDirectory;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
        | 人员身份中心按请求共享一份。
        |
        | 它内部缓存「名字 → 账号」全表映射与账号目录。一次列表请求里 `staffNames()`
        | 会被调用几十次，若每次重建映射，会员列表会从 2 次查询变成上百次。
        |
        | 唯一性约束：写完别名 / 合并账号后必须 `flush()`（`StaffDirectory::assign()`
        | 与 `merge()` 内部已做），否则同一请求内后续的归属判断仍是旧口径。
        */
        $this->app->singleton(StaffDirectory::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
        |--------------------------------------------------------------------------
        | 命名限流器（与全局 throttleApi 分离计数）
        |--------------------------------------------------------------------------
        | 为什么必须命名：Laravel 的 `throttle:6,1` 与全局 `throttleApi('120,1')`
        | 在同一个路由上会用**同一个缓存键**累加计数，限流更严的那条会被浏览产生的
        | 普通 API 请求提前打满 —— 真实故障：用户只是多翻了几页就收到 429
        | 「Too Many Attempts.」，绑定微信与发送测试通知全部失败（2026-11-04）。
        | 命名限流器（RateLimiter::for）使用独立的键前缀，互不污染。
        */
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('wx-login', fn (Request $r) => Limit::perMinute(6)->by($r->ip()));
        RateLimiter::for('wx-bind', fn (Request $r) => Limit::perMinute(10)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('wx-test', fn (Request $r) => Limit::perMinute(8)->by($r->user()?->id ?: $r->ip()));
    }
}
