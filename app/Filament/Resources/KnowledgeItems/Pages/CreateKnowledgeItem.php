<?php

namespace App\Filament\Resources\KnowledgeItems\Pages;

use App\Filament\Resources\Concerns\KnowledgeCreateAuthorization;
use App\Filament\Resources\KnowledgeItems\KnowledgeItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKnowledgeItem extends CreateRecord
{
    use KnowledgeCreateAuthorization;

    protected static string $resource = KnowledgeItemResource::class;
}
