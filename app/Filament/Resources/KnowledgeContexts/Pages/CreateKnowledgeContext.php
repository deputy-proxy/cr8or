<?php

namespace App\Filament\Resources\KnowledgeContexts\Pages;

use App\Filament\Resources\Concerns\KnowledgeCreateAuthorization;
use App\Filament\Resources\KnowledgeContexts\KnowledgeContextResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKnowledgeContext extends CreateRecord
{
    use KnowledgeCreateAuthorization;

    protected static string $resource = KnowledgeContextResource::class;
}