<?php

namespace App\Filament\Resources\KnowledgeVersions\Pages;

use App\Filament\Resources\KnowledgeVersions\KnowledgeVersionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListKnowledgeVersions extends ListRecords
{
    protected static string $resource = KnowledgeVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}