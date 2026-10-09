<?php

namespace App\Filament\Resources\AgentExecutionEventRecords;

use App\Filament\Resources\AgentExecutionEventRecords\Pages\ListAgentExecutionEventRecords;
use App\Models\AgentExecutionEventRecord;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AgentExecutionEventRecordResource extends Resource
{
    protected static ?string $model = AgentExecutionEventRecord::class;

    protected static ?string $modelLabel = 'Agent Execution Event Record';

    protected static ?string $pluralModelLabel = 'Agent Execution Event Records';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Agent Operations & Governance';

    protected static ?string $navigationLabel = 'Agent Execution Event Records';

    protected static ?int $navigationSort = 80;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('event_type')->badge()->searchable()->sortable(),
            TextColumn::make('agent_execution_id')->label('Execution')->searchable()->sortable(),
            TextColumn::make('actor_id')->label('Actor')->searchable()->sortable(),
            TextColumn::make('visibility')->badge()->searchable()->sortable(),
            TextColumn::make('occurred_at')->dateTime()->sortable(),
            TextColumn::make('correlation_id')->searchable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentExecutionEventRecords::route('/'),
        ];
    }
}
