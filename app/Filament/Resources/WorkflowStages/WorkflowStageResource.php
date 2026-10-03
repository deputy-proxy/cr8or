<?php

namespace App\Filament\Resources\WorkflowStages;

use App\Filament\Resources\WorkflowStages\Pages\ListWorkflowStages;
use App\Models\WorkflowStage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WorkflowStageResource extends Resource
{
    protected static ?string $model = WorkflowStage::class;

    protected static ?string $modelLabel = 'Workflow Stage';

    protected static ?string $pluralModelLabel = 'Workflow Stages';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Workflow Flow';

    protected static ?string $navigationLabel = 'Workflow Stages';

    protected static ?int $navigationSort = 30;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('workflow_id')->label('Workflow')->searchable()->sortable(),
            TextColumn::make('key')->searchable()->sortable(),
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('sequence')->numeric()->sortable(),
            TextColumn::make('repeatable')->badge()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkflowStages::route('/'),
        ];
    }
}
