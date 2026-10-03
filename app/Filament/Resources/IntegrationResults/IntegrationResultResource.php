<?php

namespace App\Filament\Resources\IntegrationResults;

use App\Filament\Resources\IntegrationResults\Pages\ListIntegrationResults;
use App\Models\IntegrationResult;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IntegrationResultResource extends Resource
{
    protected static ?string $model = IntegrationResult::class;

    protected static ?string $modelLabel = 'Integration Result';

    protected static ?string $pluralModelLabel = 'Integration Results';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations & External Systems';

    protected static ?string $navigationLabel = 'Integration Results';

    protected static ?int $navigationSort = 40;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('integration_job_id')->label('Integration Job')->searchable()->sortable(),
            TextColumn::make('integration_connection_id')->label('Connection')->searchable()->sortable(),
            TextColumn::make('provider')->searchable()->sortable(),
            TextColumn::make('operation')->searchable()->sortable(),
            TextColumn::make('status')->badge()->searchable()->sortable(),
            TextColumn::make('processing_status')->badge()->searchable()->sortable(),
            TextColumn::make('occurred_at')->dateTime()->sortable(),
            TextColumn::make('processed_at')->dateTime()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIntegrationResults::route('/'),
        ];
    }
}
