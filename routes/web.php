<?php

use App\Http\Controllers\Api\FeishuWebhookController;
use App\Http\Controllers\Web\AdminAccountController;
use App\Http\Controllers\Web\AdminAuthController;
use App\Http\Controllers\Web\AdminAuditController;
use App\Http\Controllers\Web\AdminDashboardController;
use App\Http\Controllers\Web\AdminMemoryController;
use App\Http\Controllers\Web\AdminSettingsController;
use App\Http\Controllers\Web\AdminSkillController;
use App\Http\Controllers\Web\AdminUserController;
use App\Http\Controllers\Web\FeishuOauthController;
use App\Http\Controllers\Web\SetupController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/feishu/events', [FeishuWebhookController::class, 'handle']);

if (! file_exists(storage_path('app/setup.lock'))) {
    Route::get('/setup', [SetupController::class, 'index']);
    Route::post('/setup', [SetupController::class, 'store']);
}

Route::middleware('installed')->group(function (): void {
    Route::get('/feishu/oauth/start', [FeishuOauthController::class, 'start'])->name('feishu.oauth.start');
    Route::get('/feishu/oauth/callback', [FeishuOauthController::class, 'callback'])->name('feishu.oauth.callback');

    Route::redirect('/', '/admin/login')->name('mifrog.intro');

    Route::get('/admin/login', [AdminAuthController::class, 'showLogin'])->name('admin.login');
    Route::post('/admin/login', [AdminAuthController::class, 'login']);

    Route::post('/admin/password/forgot/send-code', [AdminAuthController::class, 'forgotSendCode']);
    Route::post('/admin/password/forgot/verify', [AdminAuthController::class, 'forgotVerifyCode']);
    Route::post('/admin/password/forgot/reset', [AdminAuthController::class, 'forgotResetPassword']);

    Route::middleware('admin.session')->group(function (): void {
        Route::get('/admin', [AdminDashboardController::class, 'index'])->middleware('admin.permission:dashboard.view');

        Route::get('/admin/accounts', [AdminAccountController::class, 'index'])->middleware('admin.permission:admin_accounts.view');
        Route::get('/admin/accounts/create', [AdminAccountController::class, 'create'])->middleware('admin.permission:admin_accounts.create');
        Route::post('/admin/accounts', [AdminAccountController::class, 'store'])->middleware('admin.permission:admin_accounts.create');
        Route::get('/admin/accounts/{adminUser}/edit', [AdminAccountController::class, 'edit'])->middleware('admin.permission:admin_accounts.update');
        Route::post('/admin/accounts/{adminUser}', [AdminAccountController::class, 'update'])->middleware('admin.permission:admin_accounts.update');
        Route::post('/admin/accounts/{adminUser}/password', [AdminAccountController::class, 'updatePassword'])->middleware('admin.permission:admin_accounts.password');
        Route::post('/admin/accounts/{adminUser}/toggle-active', [AdminAccountController::class, 'toggleActive'])->middleware('admin.permission:admin_accounts.toggle_active');

        Route::get('/admin/settings', [AdminSettingsController::class, 'index'])->middleware('admin.permission:settings.view');
        Route::post('/admin/settings', [AdminSettingsController::class, 'update'])->middleware('admin.permission:settings.channel.update,settings.model.update,settings.enterprise.update');
        Route::post('/admin/settings/test/channel', [AdminSettingsController::class, 'testChannel'])->middleware('admin.permission:settings.channel.test');
        Route::post('/admin/settings/test/model', [AdminSettingsController::class, 'testModel'])->middleware('admin.permission:settings.model.test');
        Route::post('/admin/settings/test/model-connection', [AdminSettingsController::class, 'testModelConnection'])->middleware('admin.permission:settings.model.test');
        Route::get('/admin/settings/quota', [AdminSettingsController::class, 'quotaData'])->middleware('admin.permission:settings.quota.manage');
        Route::post('/admin/settings/quota/pool', [AdminSettingsController::class, 'saveQuotaPool'])->middleware('admin.permission:settings.quota.manage');
        Route::post('/admin/settings/quota/default', [AdminSettingsController::class, 'saveQuotaDefault'])->middleware('admin.permission:settings.quota.manage');
        Route::post('/admin/settings/quota/allocate', [AdminSettingsController::class, 'saveQuotaAllocation'])->middleware('admin.permission:settings.quota.manage');
        Route::post('/admin/settings/quota/allocate/delete', [AdminSettingsController::class, 'deleteQuotaAllocation'])->middleware('admin.permission:settings.quota.manage');

        Route::get('/admin/users', [AdminUserController::class, 'index'])->middleware('admin.permission:users.view');
        Route::post('/admin/users/sync', [AdminUserController::class, 'syncFromFeishu'])->middleware('admin.permission:users.sync');
        Route::get('/admin/users/{user}', [AdminUserController::class, 'show'])->middleware('admin.permission:users.view');
        Route::post('/admin/users/{user}/toggle-active', [AdminUserController::class, 'toggleActive'])->middleware('admin.permission:users.toggle_active');

        Route::get('/admin/skills', [AdminSkillController::class, 'index'])->middleware('admin.permission:skills.view');
        Route::get('/admin/skills/create', [AdminSkillController::class, 'create'])->middleware('admin.permission:skills.create');
        Route::post('/admin/skills', [AdminSkillController::class, 'store'])->middleware('admin.permission:skills.create');
        Route::get('/admin/skills/{skill}', [AdminSkillController::class, 'show'])->middleware('admin.permission:skills.view');
        Route::post('/admin/skills/{skill}', [AdminSkillController::class, 'update'])->middleware('admin.permission:skills.update');
        Route::post('/admin/skills/{skill}/assign', [AdminSkillController::class, 'updateAssignment'])->middleware('admin.permission:skills.assign');
        Route::post('/admin/skills/{skill}/status', [AdminSkillController::class, 'updateStatus'])->middleware('admin.permission:skills.status');
        Route::post('/admin/skills/{skill}/files/save', [AdminSkillController::class, 'saveFile'])->middleware('admin.permission:skills.files.manage');
        Route::post('/admin/skills/{skill}/files/delete', [AdminSkillController::class, 'deleteFile'])->middleware('admin.permission:skills.files.manage');
        Route::get('/admin/skills/{skill}/files/download', [AdminSkillController::class, 'downloadFile'])->middleware('admin.permission:skills.files.download');
        Route::post('/admin/skills/{skill}/delete', [AdminSkillController::class, 'destroy'])->middleware('admin.permission:skills.delete');

        Route::get('/admin/memory', [AdminMemoryController::class, 'index'])->middleware('admin.permission:memory.view');
        Route::post('/admin/memory/repair', [AdminMemoryController::class, 'repair'])->middleware('admin.permission:memory.repair');
        Route::post('/admin/memory/cleanup', [AdminMemoryController::class, 'cleanup'])->middleware('admin.permission:memory.cleanup');

        Route::get('/admin/audits', [AdminAuditController::class, 'index'])->middleware('admin.permission:audits.view');
        Route::get('/admin/audits/export', [AdminAuditController::class, 'export'])->middleware('admin.permission:audits.export');
        Route::post('/admin/audits/policies', [AdminAuditController::class, 'storePolicy'])->middleware('admin.permission:audits.policies.manage');
        Route::post('/admin/audits/policies/{policy}', [AdminAuditController::class, 'updatePolicy'])->middleware('admin.permission:audits.policies.manage');
        Route::post('/admin/audits/policies/{policy}/delete', [AdminAuditController::class, 'destroyPolicy'])->middleware('admin.permission:audits.policies.delete');

        Route::get('/admin/operation-logs', [App\Http\Controllers\Web\AdminOperationLogController::class, 'index'])->middleware('admin.permission:ops_log.view');

        Route::post('/admin/profile', [AdminAuthController::class, 'updateProfile']);
        Route::post('/admin/password/send-code', [AdminAuthController::class, 'sendPasswordCode']);
        Route::post('/admin/password/change', [AdminAuthController::class, 'changePassword']);
        Route::post('/admin/logout', [AdminAuthController::class, 'logout']);
    });
});

// ===== 数字分身 (Doppelgänger) 路由 =====
Route::middleware(['installed', 'admin.session'])->prefix('admin/doppelgangers')->name('admin.doppelgangers.')->group(function () {
    // 管理（admin 操作）
    Route::get('/', [\App\Modules\Doppelganger\Http\Controllers\AdminDoppelgangerController::class, 'index'])->name('index');
    Route::get('/create', [\App\Modules\Doppelganger\Http\Controllers\AdminDoppelgangerController::class, 'create'])->name('create');
    Route::post('/', [\App\Modules\Doppelganger\Http\Controllers\AdminDoppelgangerController::class, 'store'])->name('store');
    Route::get('/{id}', [\App\Modules\Doppelganger\Http\Controllers\AdminDoppelgangerController::class, 'show'])->whereNumber('id')->name('show');
    Route::post('/{id}/activate', [\App\Modules\Doppelganger\Http\Controllers\AdminDoppelgangerController::class, 'activate'])->whereNumber('id')->name('activate');
    Route::post('/{id}/pause', [\App\Modules\Doppelganger\Http\Controllers\AdminDoppelgangerController::class, 'pause'])->whereNumber('id')->name('pause');
    Route::post('/{id}/resume', [\App\Modules\Doppelganger\Http\Controllers\AdminDoppelgangerController::class, 'resume'])->whereNumber('id')->name('resume');
    Route::post('/{id}/revoke', [\App\Modules\Doppelganger\Http\Controllers\AdminDoppelgangerController::class, 'revoke'])->whereNumber('id')->name('revoke');
    Route::post('/{id}/extend', [\App\Modules\Doppelganger\Http\Controllers\AdminDoppelgangerController::class, 'extend'])->whereNumber('id')->name('extend');
    Route::post('/{id}/grant', [\App\Modules\Doppelganger\Http\Controllers\AdminDoppelgangerController::class, 'grant'])->whereNumber('id')->name('grant');
    Route::post('/{id}/grant/{grantId}/revoke', [\App\Modules\Doppelganger\Http\Controllers\AdminDoppelgangerController::class, 'revokeGrant'])->whereNumber(['id', 'grantId'])->name('grant.revoke');

    // 接班人使用入口（统一对话页）
    Route::get('/my', [\App\Modules\Doppelganger\Http\Controllers\DoppelgangerInvocationController::class, 'myGrants'])->name('my');
    Route::get('/{id}/chat', [\App\Modules\Doppelganger\Http\Controllers\DoppelgangerInvocationController::class, 'chat'])->whereNumber('id')->name('chat');
    Route::post('/{id}/ask', [\App\Modules\Doppelganger\Http\Controllers\DoppelgangerInvocationController::class, 'ask'])->whereNumber('id')->name('ask');
    Route::post('/{id}/draft', [\App\Modules\Doppelganger\Http\Controllers\DoppelgangerInvocationController::class, 'draft'])->whereNumber('id')->name('draft');
    Route::post('/{id}/workflow/{workflowId}/preview', [\App\Modules\Doppelganger\Http\Controllers\DoppelgangerInvocationController::class, 'workflowPreview'])->whereNumber(['id', 'workflowId'])->name('workflow.preview');
});
