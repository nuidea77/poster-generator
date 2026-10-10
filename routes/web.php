<?php

use App\Http\Controllers\Api\Admin\CreationController as AdminCreationController;
use App\Http\Controllers\Api\Admin\PlanController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CreationController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\SkillController;
use Illuminate\Support\Facades\Route;

// The SPA is served from this app, so the API uses the session (web) guard + CSRF.
Route::prefix('api/v1')->group(function () {
    Route::get('meta', [MeController::class, 'meta']);
    Route::get('me', [MeController::class, 'show']);

    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    // QPay server-to-server callback (CSRF-exempt, verified via payment/check).
    Route::match(['get', 'post'], 'payments/qpay/callback/{token}', [PaymentController::class, 'callback'])
        ->middleware('throttle:60,1')
        ->name('qpay.callback');

    Route::middleware('auth')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('me/brand', [MeController::class, 'updateBrand']);

        Route::post('payments', [PaymentController::class, 'store'])->middleware('throttle:10,1');
        Route::get('payments/{payment}', [PaymentController::class, 'show']);
        Route::post('payments/{payment}/simulate', [PaymentController::class, 'simulate']);

        Route::get('creations', [CreationController::class, 'index']);
        Route::get('creations/{creation}', [CreationController::class, 'show']);
        Route::delete('creations/{creation}', [CreationController::class, 'destroy']);

        // Plan limits (free tier included) are checked per type in CreationService.
        Route::post('creations', [CreationController::class, 'store'])->middleware('throttle:20,1');
        Route::post('creations/{creation}/retry', [CreationController::class, 'retry'])->middleware('throttle:20,1');

        Route::middleware('admin')->prefix('admin')->group(function () {
            Route::get('creations', [AdminCreationController::class, 'index']);
            Route::get('creations/{creation}', [AdminCreationController::class, 'show']);

            Route::get('plans', [PlanController::class, 'index']);
            Route::post('plans', [PlanController::class, 'store']);
            Route::put('plans/{plan}', [PlanController::class, 'update']);

            Route::get('skills', [SkillController::class, 'index']);
            Route::post('skills', [SkillController::class, 'store']);
            Route::post('skills/import', [SkillController::class, 'import']);
            Route::get('skills/{name}', [SkillController::class, 'show']);
            Route::put('skills/{skill}', [SkillController::class, 'update']);
            Route::delete('skills/{skill}', [SkillController::class, 'destroy']);
        });
    });
});

// Vue SPA (history mode)
Route::view('/{any?}', 'app')->where('any', '^(?!api|storage|up).*$')->name('login');
