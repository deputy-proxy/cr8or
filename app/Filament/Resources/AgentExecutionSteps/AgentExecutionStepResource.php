<?php

namespace App\Filament\Resources\AgentExecutionSteps;

use App\Filament\Resources\AgentExecutionSteps\Pages\ListAgentExecutionSteps;
use App\Models\AgentExecutionStep;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AgentExecutionStepResource extends Resource
{
    protected static ?string $model = AgentExecutionStep::class;

    protected static ?string $modelLabel = 'Agent Execution Step';

    protected static ?string $pluralModelLabel = 'Agent Execution Steps';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Agentic Flow';

    protected static ?string $navigationLabel = 'Agent Execution Steps';

    protected static ?int $navigationSort = 60;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('agent_execution_id')->label('Execution')->searchable()->sortable(),
            TextColumn::make('sequence')->numeric()->sortable(),
            TextColumn::make('status')->badge()->searchable()->sortable(),
            TextColumn::make('type')->badge()->searchable()->sortable(),
            TextColumn::make('started_at')->dateTime()->sortable(),
            TextColumn::make('completed_at')->dateTime()->sortable(),
            TextColumn::make('failure_code')->badge()->searchable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentExecutionSteps::route('/'),
        ];
    }
}
