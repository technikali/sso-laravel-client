<?php

use Illuminate\Support\Facades\Route;
use Technikali\SsoClient\Http\Controllers\SsoCallbackController;

Route::group(config('sso.route_group', []), function () {
    Route::get('/auth/redirect',  [SsoCallbackController::class, 'redirect'])->name('sso.redirect');
    Route::get('/auth/callback',  [SsoCallbackController::class, 'callback'])->name('sso.callback');
    Route::post('/auth/logout',   [SsoCallbackController::class, 'logout'])->name('sso.logout');
});
