<?php

use App\Http\Controllers\IntegrationWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('integrations/webhooks/{provider}', IntegrationWebhookController::class)
    ->middleware(['throttle:120,1'])
    ->name('integrations.webhook');