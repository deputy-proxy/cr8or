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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Workflow Flow';

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Workflow Executions';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('workflow.name')->label('Workflow')->searchable()->sortable(),
            TextColumn::make('workflowVersion.version')->label('Workflow Version')->searchable()->sortable(),
            TextColumn::make('currentStage.name')->label('Current Stage')->searchable()->sortable(),
            TextColumn::make('status')->label('Status')->searchable()->sortable(),
            TextColumn::make('started_at')->label('Started At')->searchable()->sortable(),
            TextColumn::make('completed_at')->label('Completed At')->searchable()->sortable(),
            TextColumn::make('failure_reason')->label('Failure Reason')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkflowExecutions::route('/'),
        ];
    }
}
