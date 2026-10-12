<?php

use App\Http\Controllers\SocialAuthorizationController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::post('social/authorization/{network}/start', [SocialAuthorizationController::class, 'start'])
        ->where('network', '[a-z0-9_-]+')
        ->name('social.authorization.start');
    Route::get('social/authorization/callback/{provider}', [SocialAuthorizationController::class, 'callback'])
        ->where('provider', '[a-z0-9_-]+')
        ->name('social.authorization.callback');
    Route::get('social/authorization/attempts/{attempt}/destinations', [SocialAuthorizationController::class, 'destinations'])
        ->name('social.authorization.destinations');
    Route::post('social/authorization/attempts/{attempt}/destinations', [SocialAuthorizationController::class, 'attach'])
        ->name('social.authorization.attach');
    Route::post('social/connections/{connection}/disconnect', [SocialAuthorizationController::class, 'disconnect'])
        ->name('social.connections.disconnect');
});

require __DIR__.'/settings.php';