<?php

namespace App\Filament\Resources\KnowledgeItems\Schemas;

use App\Models\Enterprise;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class KnowledgeItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')->options(fn () => Enterprise::query()->whereIn('organization_id', \App\Filament\Resources\KnowledgeItems\KnowledgeItemResource::authorizedOrganizationIds())->pluck('name', 'id'))->searchable()->preload()->required(),
            Select::make('knowledge_source_id')->relationship('source', 'name', fn (Builder $q) => $q->whereIn('enterprise_id', Enterprise::query()->select('id')->whereIn('organization_id', \App\Filament\Resources\KnowledgeItems\KnowledgeItemResource::authorizedOrganizationIds())))->searchable()->preload(),
            Select::make('knowledge_document_id')->relationship('document', 'title', fn (Builder $q) => $q->whereIn('enterprise_id', Enterprise::query()->select('id')->whereIn('organization_id', \App\Filament\Resources\KnowledgeItems\KnowledgeItemResource::authorizedOrganizationIds())))->searchable()->preload(),
            Select::make('knowledge_context_id')->relationship('context', 'name', fn (Builder $q) => $q->whereIn('enterprise_id', Enterprise::query()->select('id')->whereIn('organization_id', \App\Filament\Resources\KnowledgeItems\KnowledgeItemResource::authorizedOrganizationIds())))->searchable()->preload(),
            TextInput::make('title')->required()->maxLength(255),
            TextInput::make('type')->required()->maxLength(255),
            Textarea::make('summary')->rows(6),
        ]);
    }
}
