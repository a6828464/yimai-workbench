<?php

use App\Http\Middleware\EnsureUserIsEnabled;
use App\Http\Middleware\LogApiRequest;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

// 全局业务辅助函数（app/Support/helpers.php）。
// 除 composer autoload.files 外，此处 require_once 兜底加载：
// 在线升级保留服务器现有 vendor，若新版 composer.json 的 autoload.files 变更
// 而未重跑 composer dump-autoload，旧 autoload_files 列表会导致 ok()/requireSuper()
// 等函数未定义、全站 500；require_once 保证任何部署路径下助手函数必然加载。
require_once __DIR__.'/../app/Support/helpers.php';

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => null);
        // 全局限流：单账号 120 req/min。逐端点另有 7 处更严的 throttle（登录/AI/体测解析），
        // 这里补的是兜底 —— 此前 api 组不含 throttle，任何已登录账号（含离职未停用者）
        // 可无限枚举 /api/customers、/api/leads 等列表接口，无任何速率约束。
        // 120/min 对单用户远高于正常操作（约 2 req/s 才会触顶），不影响前端轮询。
        $middleware->throttleApi('120,1');
        $middleware->api(append: [EnsureUserIsEnabled::class, LogApiRequest::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });
    })->create();
