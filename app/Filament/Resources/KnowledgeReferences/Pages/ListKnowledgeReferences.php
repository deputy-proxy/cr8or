<?php

namespace App\Filament\Resources\KnowledgeReferences\Pages;

use App\Filament\Resources\KnowledgeReferences\KnowledgeReferenceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListKnowledgeReferences extends ListRecords
{
    protected static string $resource = KnowledgeReferenceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}