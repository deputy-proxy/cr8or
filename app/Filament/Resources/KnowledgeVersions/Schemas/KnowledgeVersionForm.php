<?php

namespace App\Filament\Resources\KnowledgeVersions\Schemas;

use App\Models\Enterprise;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class KnowledgeVersionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')
                ->options(fn (): array => Enterprise::query()
                    ->whereIn('organization_id', \App\Filament\Resources\KnowledgeVersions\KnowledgeVersionResource::authorizedOrganizationIds())
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->preload()
                ->required(),
            Select::make('knowledge_item_id')
                ->relationship('item', 'title')
                ->searchable()
                ->preload()
                ->required(),
            Textarea::make('content')->rows(8)->required(),
            KeyValue::make('context_snapshot')->keyLabel('Context key')->valueLabel('Context value'),
        ]);
    }
}
