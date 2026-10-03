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

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Workflow Versions';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('workflow.name')->label('Workflow')->searchable()->sortable(),
            TextColumn::make('enterprise.name')->label('Enterprise')->searchable()->sortable(),
            TextColumn::make('version')->label('Version')->searchable()->sortable(),
            TextColumn::make('status')->label('Status')->searchable()->sortable(),
            TextColumn::make('created_at')->label('Created At')->searchable()->sortable(),
            TextColumn::make('published_at')->label('Published At')->searchable()->sortable(),
            TextColumn::make('retired_at')->label('Retired At')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkflowVersions::route('/'),
        ];
    }
}
