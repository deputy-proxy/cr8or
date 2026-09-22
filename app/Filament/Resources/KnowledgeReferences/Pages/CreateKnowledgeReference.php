<?php

namespace App\Filament\Resources\KnowledgeReferences\Pages;

use App\Filament\Resources\Concerns\KnowledgeCreateAuthorization;
use App\Filament\Resources\KnowledgeReferences\KnowledgeReferenceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKnowledgeReference extends CreateRecord
{
    use KnowledgeCreateAuthorization;

    protected static string $resource = KnowledgeReferenceResource::class;
}