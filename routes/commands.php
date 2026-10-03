<?php

use App\Http\Controllers\CommandWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('commands/{capability}', CommandWebhookController::class)
    ->middleware(['throttle:120,1'])
    ->name('commands.webhook');