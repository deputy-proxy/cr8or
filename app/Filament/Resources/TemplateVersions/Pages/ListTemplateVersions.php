<?php

namespace App\Filament\Resources\TemplateVersions\Pages;

use App\Filament\Resources\TemplateVersions\TemplateVersionResource;
use Filament\Resources\Pages\ListRecords;

class ListTemplateVersions extends ListRecords
{
    protected static string $resource = TemplateVersionResource::class;
}