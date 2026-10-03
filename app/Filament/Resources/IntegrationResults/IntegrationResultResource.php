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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations & External Systems';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Integration Results';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('provider')->label('Provider')->searchable()->sortable(),
            TextColumn::make('operation')->label('Operation')->searchable()->sortable(),
            TextColumn::make('external_result_id')->label('External Result Id')->searchable()->sortable(),
            TextColumn::make('status')->label('Status')->searchable()->sortable(),
            TextColumn::make('processing_status')->label('Processing Status')->searchable()->sortable(),
            TextColumn::make('occurred_at')->label('Occurred At')->searchable()->sortable(),
            TextColumn::make('processed_at')->label('Processed At')->searchable()->sortable(),
            TextColumn::make('failure_code')->label('Failure Code')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIntegrationResults::route('/'),
        ];
    }
}
