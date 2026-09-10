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
        $middleware->api(append: [EnsureUserIsEnabled::class, LogApiRequest::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });
    })->create();
