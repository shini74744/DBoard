<?php

use Illuminate\Support\Facades\Route;
use Plugin\SubscriptionGuard\Controllers\SubscriptionGuardController;

// 管理员API
Route::group([
    'prefix'     => 'api/v2/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))) . '/subscription-guard',
    'middleware' => ['api', 'admin'],
], function () {
    Route::get('/dashboard', [SubscriptionGuardController::class, 'dashboard']);
    Route::get('/logs', [SubscriptionGuardController::class, 'getLogs']);
    Route::get('/abuse', [SubscriptionGuardController::class, 'getAbuseList']);
    Route::get('/stats', [SubscriptionGuardController::class, 'getUserStats']);
    Route::get('/user-ip-detail', [SubscriptionGuardController::class, 'getUserIpDetail']);
    Route::post('/ban', [SubscriptionGuardController::class, 'banUser']);
    Route::post('/unban', [SubscriptionGuardController::class, 'unbanUser']);
    Route::post('/clean-logs', [SubscriptionGuardController::class, 'cleanLogs']);
});
