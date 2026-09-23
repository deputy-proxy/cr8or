<?php

namespace App\Filament\Resources\KnowledgeContexts\Schemas;

use App\Models\Enterprise;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class KnowledgeContextForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')
                ->options(fn (): array => Enterprise::query()
                    ->whereIn('organization_id', \App\Filament\Resources\KnowledgeContexts\KnowledgeContextResource::authorizedOrganizationIds())
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->preload()
                ->required(),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('type')->required()->maxLength(255),
            Textarea::make('description')->rows(4),
            KeyValue::make('data')->keyLabel('Key')->valueLabel('Value'),
        ]);
    }
}
