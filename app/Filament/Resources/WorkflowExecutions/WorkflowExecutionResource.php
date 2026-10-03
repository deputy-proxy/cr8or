<?php

namespace App\Filament\Resources\WorkflowExecutions;

use App\Filament\Resources\WorkflowExecutions\Pages\ListWorkflowExecutions;
use App\Models\WorkflowExecution;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WorkflowExecutionResource extends Resource
{
    protected static ?string $model = WorkflowExecution::class;

    protected static ?string $modelLabel = 'Workflow Execution';

    protected static ?string $pluralModelLabel = 'Workflow Executions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Workflow Flow';

    protected static ?string $navigationLabel = 'Workflow Executions';

    protected static ?int $navigationSort = 50;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('workflow_id')->label('Workflow')->searchable()->sortable(),
            TextColumn::make('workflow_version_id')->label('Workflow Version')->searchable()->sortable(),
            TextColumn::make('status')->badge()->searchable()->sortable(),
            TextColumn::make('current_stage_key')->label('Current Stage')->searchable()->sortable(),
            TextColumn::make('started_at')->dateTime()->sortable(),
            TextColumn::make('completed_at')->dateTime()->sortable(),
            TextColumn::make('failure_reason')->limit(80),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkflowExecutions::route('/'),
        ];
    }
}
