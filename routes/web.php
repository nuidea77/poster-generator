<?php

use App\Http\Controllers\Api\Admin\CostController;
use App\Http\Controllers\Api\Admin\CreationController as AdminCreationController;
use App\Http\Controllers\Api\Admin\PlanController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CreationController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\SkillController;
use Illuminate\Support\Facades\Route;

// SPA нь энэ апп-аас үйлчилдэг тул API нь session (web) guard + CSRF ашиглана.
Route::prefix('api/v1')->group(function () {
    // Нийтэд нээлттэй: апп-ын тохиргоо (meta) ба одоогийн хэрэглэгч (нэвтрээгүй бол хоосон).
    Route::get('meta', [MeController::class, 'meta']);
    Route::get('me', [MeController::class, 'show']);

    // Бүртгүүлэх / нэвтрэх: brute force-оос хамгаалж throttle тавьсан.
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    // QPay-ийн server-to-server callback (CSRF-ээс чөлөөлсөн, payment/check-ээр баталгаажуулна).
    Route::match(['get', 'post'], 'payments/qpay/callback/{token}', [PaymentController::class, 'callback'])
        ->middleware('throttle:60,1')
        ->name('qpay.callback');

    // Нэвтэрсэн хэрэглэгчийн route-ууд.
    Route::middleware('auth')->group(function () {
        // Гарах, брэндийн мэдээлэл (лого, холбоо барих) шинэчлэх.
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('me/brand', [MeController::class, 'updateBrand']);

        // Төлбөр: QPay нэхэмжлэх үүсгэх, төлөв шалгах, локалд төлбөр дуурайх (QPAY_FAKE).
        Route::post('payments', [PaymentController::class, 'store'])->middleware('throttle:10,1');
        Route::get('payments/{payment}', [PaymentController::class, 'show']);
        Route::post('payments/{payment}/simulate', [PaymentController::class, 'simulate']);

        // Хэрэглэгчийн бүтээлүүд (постер / reel): жагсаалт, дэлгэрэнгүй, устгах.
        Route::get('creations', [CreationController::class, 'index']);
        Route::get('creations/{creation}', [CreationController::class, 'show']);
        Route::delete('creations/{creation}', [CreationController::class, 'destroy']);

        // Багцын хязгаарыг (free tier орно) CreationService төрөл тус бүрээр шалгана.
        Route::post('creations', [CreationController::class, 'store'])->middleware('throttle:20,1');
        Route::post('creations/{creation}/retry', [CreationController::class, 'retry'])->middleware('throttle:20,1');

        // Админ хэсэг: зөвхөн is_admin хэрэглэгч ('admin' middleware).
        Route::middleware('admin')->prefix('admin')->group(function () {
            // Бүх бүтээл, дотоод мэдээлэл (assets, steps, error_detail) AdminCreationResource-оор.
            Route::get('creations', [AdminCreationController::class, 'index']);
            Route::get('creations/{creation}', [AdminCreationController::class, 'show']);

            // Өртөг: credit үнэ, бодит API зардал, алдагдал.
            Route::get('costs', CostController::class);

            // Багцуудыг удирдах.
            Route::get('plans', [PlanController::class, 'index']);
            Route::post('plans', [PlanController::class, 'store']);
            Route::put('plans/{plan}', [PlanController::class, 'update']);

            // Skill-үүд: custom skill CRUD, Claude SKILL.md импорт, bundled skill-ийг дарж бичих / сэргээх.
            Route::get('skills', [SkillController::class, 'index']);
            Route::post('skills', [SkillController::class, 'store']);
            Route::post('skills/import', [SkillController::class, 'import']);
            Route::get('skills/{name}', [SkillController::class, 'show']);
            Route::put('skills/bundled/{name}', [SkillController::class, 'updateBundled']);
            Route::delete('skills/bundled/{name}', [SkillController::class, 'resetBundled']);
            Route::put('skills/{skill}', [SkillController::class, 'update']);
            Route::delete('skills/{skill}', [SkillController::class, 'destroy']);
        });
    });
});

// Vue SPA (history mode): api, storage, up-ээс бусад бүх замыг SPA руу чиглүүлнэ.
Route::view('/{any?}', 'app')->where('any', '^(?!api|storage|up).*$')->name('login');
