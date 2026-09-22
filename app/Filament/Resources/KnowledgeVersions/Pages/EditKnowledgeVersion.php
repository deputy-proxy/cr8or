<?php

namespace App\Filament\Resources\KnowledgeVersions\Pages;

use App\Filament\Resources\Concerns\KnowledgeEditIntegrity;
use App\Filament\Resources\KnowledgeVersions\KnowledgeVersionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditKnowledgeVersion extends EditRecord
{
    use KnowledgeEditIntegrity;

    protected static string $resource = KnowledgeVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}