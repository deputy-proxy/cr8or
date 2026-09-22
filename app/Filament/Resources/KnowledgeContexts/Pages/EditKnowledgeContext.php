<?php

namespace App\Filament\Resources\KnowledgeContexts\Pages;

use App\Filament\Resources\Concerns\KnowledgeEditIntegrity;
use App\Filament\Resources\KnowledgeContexts\KnowledgeContextResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditKnowledgeContext extends EditRecord
{
    use KnowledgeEditIntegrity;

    protected static string $resource = KnowledgeContextResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}