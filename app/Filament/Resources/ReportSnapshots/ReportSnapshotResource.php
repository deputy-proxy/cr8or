<?php

namespace App\Filament\Resources\ReportSnapshots;

use App\Filament\Resources\ReportSnapshots\Pages\ListReportSnapshots;
use App\Models\ReportSnapshot;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReportSnapshotResource extends Resource
{
    protected static ?string $model = ReportSnapshot::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Reporting & Analytics';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Report Snapshots';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('report.report_type')->label('Report Type')->searchable()->sortable(),
            TextColumn::make('captured_at')->label('Captured At')->searchable()->sortable(),
            TextColumn::make('period_start')->label('Period Start')->searchable()->sortable(),
            TextColumn::make('period_end')->label('Period End')->searchable()->sortable(),
            TextColumn::make('methodology_version')->label('Methodology Version')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReportSnapshots::route('/'),
        ];
    }
}
