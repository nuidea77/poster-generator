<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureSubscribed;
use App\Services\AI\Exceptions\AiException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'subscribed' => EnsureSubscribed::class,
            'admin' => EnsureAdmin::class,
        ]);

        // QPay posts server-to-server without our CSRF token.
        $middleware->validateCsrfTokens(except: ['api/v1/payments/qpay/callback/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Never leak provider/model details to customers.
        $exceptions->render(function (AiException $e, Request $request) {
            report($e);

            return response()->json(['message' => 'Үйлчилгээ түр ажиллахгүй байна. Дахин оролдоно уу.'], 502);
        });
    })->create();
