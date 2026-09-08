<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);
        try {
            $response = $next($request);
        } catch (Throwable $error) {
            Log::channel('system_error')->error('API request failed', $this->context($request, $startedAt) + [
                'exception' => $error::class,
                'error' => mb_substr($error->getMessage(), 0, 500),
            ]);
            throw $error;
        }

        $context = $this->context($request, $startedAt) + ['status' => $response->getStatusCode()];
        Log::channel('runtime')->info('API request completed', $context);
        if ($response->getStatusCode() >= 500) {
            Log::channel('system_error')->error('API request returned server error', $context);
        }

        return $response;
    }

    private function context(Request $request, float $startedAt): array
    {
        return [
            'method' => $request->method(),
            'path' => $request->path(),
            'user_id' => $request->user()?->id,
            'request_id' => $request->header('X-Request-ID'),
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }
}
