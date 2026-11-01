<?php

use App\Http\Controllers\GitHubWebhookController;
use App\Http\Controllers\IntegrationWebhookController;
use App\Http\Controllers\ProjectReferenceController;
use App\Http\Controllers\WebsiteEventController;
use App\Http\Middleware\EnsureIntegrationClientCredentials;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Middleware\CheckToken;

Route::post('integrations/webhooks/github', GitHubWebhookController::class)
    ->middleware(['throttle:120,1'])
    ->name('integrations.github.webhook');

Route::post('integrations/webhooks/{provider}', IntegrationWebhookController::class)
    ->middleware(['throttle:120,1'])
    ->name('integrations.webhook');
Route::match(['post', 'options'], 'api/events/website', WebsiteEventController::class)
    ->middleware(['throttle:60,1'])
    ->name('api.events.website');

// CR8OR Media calls its tenant a Project; the authoritative CR8OR record is an Enterprise.
Route::prefix('api/integrations/project-references')
    ->middleware([CheckToken::using('enterprise:read'), EnsureIntegrationClientCredentials::class, 'throttle:120,1'])
    ->name('integrations.project-references.')
    ->group(function (): void {
        Route::get('/', [ProjectReferenceController::class, 'index'])->name('index');
        Route::post('/validate', [ProjectReferenceController::class, 'validate'])->name('validate');
    });
