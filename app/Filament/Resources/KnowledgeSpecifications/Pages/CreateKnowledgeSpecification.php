<?php

namespace App\Filament\Resources\KnowledgeSpecifications\Pages;

use App\Filament\Resources\Concerns\KnowledgeCreateAuthorization;
use App\Filament\Resources\KnowledgeSpecifications\KnowledgeSpecificationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKnowledgeSpecification extends CreateRecord
{
    use KnowledgeCreateAuthorization;

    protected static string $resource = KnowledgeSpecificationResource::class;
}