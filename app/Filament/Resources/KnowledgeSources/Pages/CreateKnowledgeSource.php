<?php

namespace App\Filament\Resources\KnowledgeSources\Pages;

use App\Filament\Resources\Concerns\KnowledgeCreateAuthorization;
use App\Filament\Resources\KnowledgeSources\KnowledgeSourceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKnowledgeSource extends CreateRecord
{
    use KnowledgeCreateAuthorization;

    protected static string $resource = KnowledgeSourceResource::class;
}
