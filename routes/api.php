<?php

use App\Http\Controllers\Api\Admin\ConfigController as AdminConfigController;
use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\SkillController as AdminSkillController;
use App\Http\Controllers\Api\FeishuWebhookController;
use App\Http\Controllers\Api\RunStreamController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('installed')->group(function (): void {
    Route::get('/runs/{run}/stream', [RunStreamController::class, 'stream'])->middleware('run.stream');

    Route::prefix('admin')->middleware('admin.token')->group(function (): void {
        Route::get('/dashboard', [AdminDashboardController::class, 'index']);

        Route::get('/settings/feishu', [AdminConfigController::class, 'getFeishu']);
        Route::put('/settings/feishu', [AdminConfigController::class, 'setFeishu']);
        Route::get('/settings/model', [AdminConfigController::class, 'getModel']);
        Route::put('/settings/model', [AdminConfigController::class, 'setModel']);
        Route::get('/settings/quota', [AdminConfigController::class, 'getQuota']);
        Route::put('/settings/quota', [AdminConfigController::class, 'setQuota']);

        Route::get('/skills', [AdminSkillController::class, 'index']);
        Route::post('/skills', [AdminSkillController::class, 'store']);
        Route::put('/skills/{skill}', [AdminSkillController::class, 'update']);
        Route::post('/skills/{skill}/assign', [AdminSkillController::class, 'assign']);
        Route::put('/skills/{skill}/assign', [AdminSkillController::class, 'assign']);
    });
});
