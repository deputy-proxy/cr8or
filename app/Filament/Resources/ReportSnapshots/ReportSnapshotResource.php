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

    protected static ?string $navigationLabel = 'ReportSnapshots';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReportSnapshots::route('/'),
        ];
    }
}
