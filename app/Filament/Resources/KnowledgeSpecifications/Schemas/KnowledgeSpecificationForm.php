<?php

namespace App\Filament\Resources\KnowledgeSpecifications\Schemas;

use App\Models\Enterprise;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class KnowledgeSpecificationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')->options(fn () => Enterprise::query()->whereIn('organization_id', \App\Filament\Resources\KnowledgeSpecifications\KnowledgeSpecificationResource::authorizedOrganizationIds())->pluck('name', 'id'))->searchable()->preload()->required(),
            Select::make('knowledge_item_id')->relationship('item', 'title', fn (Builder $q) => $q->whereIn('enterprise_id', Enterprise::query()->select('id')->whereIn('organization_id', \App\Filament\Resources\KnowledgeSpecifications\KnowledgeSpecificationResource::authorizedOrganizationIds())))->searchable()->preload(),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('version')->required()->maxLength(255),
            Textarea::make('content')->rows(6)->required(),
        ]);
    }
}
