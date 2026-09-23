<?php

namespace App\Filament\Resources\GenerationRequests\Pages;

use App\Filament\Resources\GenerationRequests\GenerationRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListGenerationRequests extends ListRecords
{
    protected static string $resource = GenerationRequestResource::class;
}