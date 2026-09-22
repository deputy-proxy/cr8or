<?php

namespace App\Filament\Resources\KnowledgeSpecifications\Pages;

use App\Filament\Resources\KnowledgeSpecifications\KnowledgeSpecificationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListKnowledgeSpecifications extends ListRecords
{
    protected static string $resource = KnowledgeSpecificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}