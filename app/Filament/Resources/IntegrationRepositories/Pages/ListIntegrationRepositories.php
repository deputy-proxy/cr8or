<?php

namespace App\Filament\Resources\IntegrationRepositories\Pages;

use App\Filament\Resources\IntegrationRepositories\IntegrationRepositoryResource;
use Filament\Resources\Pages\ListRecords;

class ListIntegrationRepositories extends ListRecords
{
    protected static string $resource = IntegrationRepositoryResource::class;
}
