<?php

namespace App\Filament\Resources\GitHubWebhookDeliveries\Pages;

use App\Filament\Resources\GitHubWebhookDeliveries\GitHubWebhookDeliveryResource;
use Filament\Resources\Pages\ListRecords;

class ListGitHubWebhookDeliveries extends ListRecords
{
    protected static string $resource = GitHubWebhookDeliveryResource::class;
}
