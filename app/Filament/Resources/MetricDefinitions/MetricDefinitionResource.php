<?php

namespace App\Filament\Resources\MetricDefinitions;

use App\Filament\Resources\MetricDefinitions\Pages\ListMetricDefinitions;
use App\Models\MetricDefinition;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MetricDefinitionResource extends Resource
{
    protected static ?string $model = MetricDefinition::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Enterprise Context';

    protected static ?int $navigationSort = 70;

    protected static ?string $navigationLabel = 'Metric Definitions';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('key')->label('Key')->searchable()->sortable(),
            TextColumn::make('name')->label('Name')->searchable()->sortable(),
            TextColumn::make('unit')->label('Unit')->searchable()->sortable(),
            TextColumn::make('status')->label('Status')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMetricDefinitions::route('/'),
        ];
    }
}
