<?php

namespace App\Filament\Resources\KnowledgeSpecifications\Pages;

use App\Filament\Resources\Concerns\KnowledgeEditIntegrity;
use App\Filament\Resources\KnowledgeSpecifications\KnowledgeSpecificationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditKnowledgeSpecification extends EditRecord
{
    use KnowledgeEditIntegrity;

    protected static string $resource = KnowledgeSpecificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}