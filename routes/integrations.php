<?php

use App\Http\Controllers\IntegrationWebhookController;
use App\Http\Controllers\WebsiteEventController;
use Illuminate\Support\Facades\Route;

Route::post('integrations/webhooks/{provider}', IntegrationWebhookController::class)
    ->middleware(['throttle:120,1'])
    ->name('integrations.webhook');
Route::match(['post', 'options'], 'api/events/website', WebsiteEventController::class)
    ->middleware(['throttle:60,1'])
    ->name('api.events.website');
