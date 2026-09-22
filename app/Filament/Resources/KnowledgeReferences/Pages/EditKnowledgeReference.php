<?php

namespace App\Filament\Resources\KnowledgeReferences\Pages;

use App\Filament\Resources\Concerns\KnowledgeEditIntegrity;
use App\Filament\Resources\KnowledgeReferences\KnowledgeReferenceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditKnowledgeReference extends EditRecord
{
    use KnowledgeEditIntegrity;

    protected static string $resource = KnowledgeReferenceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}