<?php

namespace App\Filament\Resources\KnowledgeDocuments\Pages;

use App\Filament\Resources\Concerns\KnowledgeCreateAuthorization;
use App\Filament\Resources\KnowledgeDocuments\KnowledgeDocumentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKnowledgeDocument extends CreateRecord
{
    use KnowledgeCreateAuthorization;

    protected static string $resource = KnowledgeDocumentResource::class;
}
