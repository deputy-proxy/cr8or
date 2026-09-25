<?php

namespace App\Filament\Resources\KnowledgeItems\Schemas;

use App\Models\Enterprise;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class KnowledgeItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')->options(fn () => Enterprise::query()->whereIn('organization_id', \App\Filament\Resources\KnowledgeItems\KnowledgeItemResource::authorizedOrganizationIds())->pluck('name', 'id'))->searchable()->preload()->required(),
            Select::make('knowledge_source_id')->relationship('source', 'name')->searchable()->preload(),
            Select::make('knowledge_document_id')->relationship('document', 'title')->searchable()->preload(),
            Select::make('knowledge_context_id')->relationship('context', 'name')->searchable()->preload(),
            TextInput::make('title')->required()->maxLength(255),
            TextInput::make('type')->required()->maxLength(255),
            Textarea::make('summary')->rows(6),
        ]);
    }
}
