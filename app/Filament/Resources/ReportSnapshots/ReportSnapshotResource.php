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

    protected static ?string $modelLabel = 'Report Snapshot';

    protected static ?string $pluralModelLabel = 'Report Snapshots';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Reporting & Analytics';

    protected static ?string $navigationLabel = 'Report Snapshots';

    protected static ?int $navigationSort = 20;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('report_id')->label('Report')->searchable()->sortable(),
            TextColumn::make('captured_at')->dateTime()->sortable(),
            TextColumn::make('period_start')->date()->sortable(),
            TextColumn::make('period_end')->date()->sortable(),
            TextColumn::make('methodology_version')->searchable()->sortable(),
            TextColumn::make('source_fingerprint')->label('Source Fingerprint')->searchable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReportSnapshots::route('/'),
        ];
    }
}
