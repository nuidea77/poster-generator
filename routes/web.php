<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\GenerateController;
use App\Http\Controllers\Api\GenerationController;
use App\Http\Controllers\Api\SkillController;
use App\Http\Controllers\Api\VideoController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    Route::get('config', [GenerateController::class, 'config']);
    Route::post('generate/poster', [GenerateController::class, 'poster']);
    Route::post('generate/reel', [GenerateController::class, 'reel']);
    Route::post('images', [GenerateController::class, 'image']);
    Route::post('uploads', [GenerateController::class, 'upload']);
    Route::post('videos/convert', [VideoController::class, 'convert']);

    Route::apiResource('generations', GenerationController::class)->except('store');
    Route::apiResource('agent-runs', AgentController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::get('skills', [SkillController::class, 'index']);
    Route::post('skills', [SkillController::class, 'store']);
    Route::get('skills/{name}', [SkillController::class, 'show']);
    Route::put('skills/{skill}', [SkillController::class, 'update']);
    Route::delete('skills/{skill}', [SkillController::class, 'destroy']);
});

// Vue SPA
Route::view('/{any?}', 'app')->where('any', '^(?!api|storage).*$');
