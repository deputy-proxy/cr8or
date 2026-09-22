<?php

namespace App\Filament\Resources\KnowledgeContexts\Pages;

use App\Filament\Resources\KnowledgeContexts\KnowledgeContextResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListKnowledgeContexts extends ListRecords
{
    protected static string $resource = KnowledgeContextResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
