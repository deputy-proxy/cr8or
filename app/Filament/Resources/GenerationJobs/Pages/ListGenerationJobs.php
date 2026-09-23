<?php

namespace App\Filament\Resources\GenerationJobs\Pages;

use App\Filament\Resources\GenerationJobs\GenerationJobResource;
use Filament\Resources\Pages\ListRecords;

class ListGenerationJobs extends ListRecords
{
    protected static string $resource = GenerationJobResource::class;
}