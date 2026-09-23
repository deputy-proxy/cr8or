<?php

namespace App\Filament\Resources\ExternalResources\Pages;

use App\Filament\Resources\ExternalResources\ExternalResourceResource;
use Filament\Resources\Pages\ListRecords;

class ListExternalResources extends ListRecords
{
    protected static string $resource = ExternalResourceResource::class;
}