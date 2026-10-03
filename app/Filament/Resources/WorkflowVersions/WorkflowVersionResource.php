<?php

namespace App\Filament\Resources\WorkflowVersions;

use App\Filament\Resources\WorkflowVersions\Pages\ListWorkflowVersions;
use App\Models\WorkflowVersion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WorkflowVersionResource extends Resource
{
    protected static ?string $model = WorkflowVersion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Workflow Flow';

    protected static ?string $navigationLabel = 'WorkflowVersions';

    protected static ?int $navigationSort = 20;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkflowVersions::route('/'),
        ];
    }
}
