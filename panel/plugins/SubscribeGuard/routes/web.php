<?php

use Illuminate\Support\Facades\Route;

$securePath = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));

Route::get('/' . $securePath . '/subscribe-guard', function () use ($securePath) {
    return view('SubscribeGuard::logs', [
        'secure_path' => $securePath,
    ]);
})->name('subscribe-guard.logs');