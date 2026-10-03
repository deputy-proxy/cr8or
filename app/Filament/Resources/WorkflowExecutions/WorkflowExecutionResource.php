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

    protected static ?string $navigationLabel = 'WorkflowExecution s';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkflowExecutions::route('/'),
        ];
    }
}
