<?php

namespace App\Filament\Resources\KnowledgeReferences\Schemas;

use App\Models\Enterprise;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class KnowledgeReferenceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')->options(fn () => Enterprise::query()->whereIn('organization_id', \App\Filament\Resources\KnowledgeReferences\KnowledgeReferenceResource::authorizedOrganizationIds())->pluck('name', 'id'))->searchable()->preload()->required(),
            Select::make('knowledge_item_id')->relationship('item', 'title', fn (Builder $q) => $q->whereIn('enterprise_id', Enterprise::query()->select('id')->whereIn('organization_id', \App\Filament\Resources\KnowledgeReferences\KnowledgeReferenceResource::authorizedOrganizationIds())))->searchable()->preload(),
            Select::make('knowledge_document_id')->relationship('document', 'title', fn (Builder $q) => $q->whereIn('enterprise_id', Enterprise::query()->select('id')->whereIn('organization_id', \App\Filament\Resources\KnowledgeReferences\KnowledgeReferenceResource::authorizedOrganizationIds())))->searchable()->preload(),
            TextInput::make('type')->required()->maxLength(255),
            TextInput::make('label')->required()->maxLength(255),
            TextInput::make('locator')->required()->maxLength(255),
        ]);
    }
}