<?php

use Illuminate\Support\Facades\Route;
use Plugin\SubscribeGuard\Controllers\LogController;

$securePath = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));

$registerRoutes = function () {
    Route::get('/logs', [LogController::class, 'index']);
    Route::delete('/logs', [LogController::class, 'clear']);
    Route::post('/clean-logs', [LogController::class, 'clear']);
};

Route::group([
    'prefix' => 'api/v2/' . $securePath . '/subscribe-guard',
    'middleware' => ['admin'],
], $registerRoutes);

Route::group([
    'prefix' => 'api/v1/plugin/subscribe-guard',
    'middleware' => ['admin'],
], $registerRoutes);