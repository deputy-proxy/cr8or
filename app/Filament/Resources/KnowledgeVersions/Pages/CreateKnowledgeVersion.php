<?php

namespace App\Filament\Resources\KnowledgeVersions\Pages;

use App\Filament\Resources\Concerns\KnowledgeCreateAuthorization;
use App\Filament\Resources\KnowledgeVersions\KnowledgeVersionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKnowledgeVersion extends CreateRecord
{
    use KnowledgeCreateAuthorization;

    protected static string $resource = KnowledgeVersionResource::class;
}
