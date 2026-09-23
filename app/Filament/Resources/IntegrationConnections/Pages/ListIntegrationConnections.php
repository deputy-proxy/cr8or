<?php

namespace App\Filament\Resources\IntegrationConnections\Pages;

use App\Filament\Resources\IntegrationConnections\IntegrationConnectionResource;
use Filament\Resources\Pages\ListRecords;

class ListIntegrationConnections extends ListRecords
{
    protected static string $resource = IntegrationConnectionResource::class;
}
