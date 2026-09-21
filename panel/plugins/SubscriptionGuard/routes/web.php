<?php

use Illuminate\Support\Facades\Route;

$securePath = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));

// 仪表盘页面 (与管理后台同一动态路径前缀，靠 secure_path 保护，数据接口走 Sanctum 认证)
Route::get('/' . $securePath . '/subscription-guard', function () {
    return view('SubscriptionGuard::dashboard', [
        'secure_path' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))),
    ]);
})->name('subscription-guard.dashboard');
