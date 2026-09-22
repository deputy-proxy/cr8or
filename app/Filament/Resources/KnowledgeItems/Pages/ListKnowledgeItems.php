<?php

namespace App\Filament\Resources\KnowledgeItems\Pages;

use App\Filament\Resources\KnowledgeItems\KnowledgeItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListKnowledgeItems extends ListRecords
{
    protected static string $resource = KnowledgeItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}