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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Reporting & Analytics';

    protected static ?string $navigationLabel = 'ReportMetricValues';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReportMetricValues::route('/'),
        ];
    }
}
