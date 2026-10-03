<?php

namespace App\Filament\Resources\Reports;

use App\Filament\Resources\Reports\Pages\ListReports;
use App\Models\Report;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReportResource extends Resource
{
    protected static ?string $model = Report::class;

    protected static ?string $modelLabel = 'Report';

    protected static ?string $pluralModelLabel = 'Reports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Reporting & Analytics';

    protected static ?string $navigationLabel = 'Reports';

    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('enterprise_id')->label('Enterprise')->searchable()->sortable(),
            TextColumn::make('report_type')->badge()->searchable()->sortable(),
            TextColumn::make('status')->badge()->searchable()->sortable(),
            TextColumn::make('period_start')->date()->sortable(),
            TextColumn::make('period_end')->date()->sortable(),
            TextColumn::make('generated_at')->dateTime()->sortable(),
            TextColumn::make('methodology_version')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReports::route('/'),
        ];
    }
}
