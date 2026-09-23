<?php

namespace App\Filament\Resources\KnowledgeSources\Schemas;

use App\Models\Enterprise;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class KnowledgeSourceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')
                ->options(fn (): array => Enterprise::query()
                    ->whereIn('organization_id', \App\Filament\Resources\KnowledgeSources\KnowledgeSourceResource::authorizedOrganizationIds())
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->preload()
                ->required(),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('type')->required()->maxLength(255),
            Textarea::make('description')->rows(4),
            TextInput::make('uri')->url()->maxLength(2048),
            KeyValue::make('metadata')->keyLabel('Key')->valueLabel('Value'),
        ]);
    }
}
