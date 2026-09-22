<?php

namespace App\Filament\Resources\KnowledgeItems\Pages;

use App\Filament\Resources\Concerns\KnowledgeEditIntegrity;
use App\Filament\Resources\KnowledgeItems\KnowledgeItemResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditKnowledgeItem extends EditRecord
{
    use KnowledgeEditIntegrity;

    protected static string $resource = KnowledgeItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}