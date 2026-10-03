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

    protected static ?string $modelLabel = 'Metric Definition';

    protected static ?string $pluralModelLabel = 'Metric Definitions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Enterprise Context';

    protected static ?string $navigationLabel = 'Metric Definitions';

    protected static ?int $navigationSort = 70;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('key')->searchable()->sortable(),
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('unit')->searchable()->sortable(),
            TextColumn::make('status')->badge()->searchable()->sortable(),
            TextColumn::make('description')->limit(80),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMetricDefinitions::route('/'),
        ];
    }
}
