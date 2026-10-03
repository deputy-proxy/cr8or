<?php

namespace App\Filament\Resources\ReportMetricValues;

use App\Filament\Resources\ReportMetricValues\Pages\ListReportMetricValues;
use App\Models\ReportMetricValue;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReportMetricValueResource extends Resource
{
    protected static ?string $model = ReportMetricValue::class;

    protected static ?string $modelLabel = 'Report Metric Value';

    protected static ?string $pluralModelLabel = 'Report Metric Values';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Reporting & Analytics';

    protected static ?string $navigationLabel = 'Report Metric Values';

    protected static ?int $navigationSort = 30;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('report_id')->label('Report')->searchable()->sortable(),
            TextColumn::make('metric_definition_id')->label('Metric')->searchable()->sortable(),
            TextColumn::make('value')->numeric()->sortable(),
            TextColumn::make('unit')->searchable()->sortable(),
            TextColumn::make('calculation')->limit(80),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReportMetricValues::route('/'),
        ];
    }
}
