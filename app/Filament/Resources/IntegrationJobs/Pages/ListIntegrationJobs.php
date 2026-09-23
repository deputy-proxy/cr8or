<?php

namespace App\Filament\Resources\IntegrationJobs\Pages;

use App\Filament\Resources\IntegrationJobs\IntegrationJobResource;
use Filament\Resources\Pages\ListRecords;

class ListIntegrationJobs extends ListRecords
{
    protected static string $resource = IntegrationJobResource::class;
}
