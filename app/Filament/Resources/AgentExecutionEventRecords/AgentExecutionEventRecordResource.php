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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Agentic Flow';

    protected static ?int $navigationSort = 70;

    protected static ?string $navigationLabel = 'Agent Execution Event Records';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('execution.id')->label('Execution ID')->searchable()->sortable(),
            TextColumn::make('event_type')->label('Event Type')->searchable()->sortable(),
            TextColumn::make('visibility')->label('Visibility')->searchable()->sortable(),
            TextColumn::make('version')->label('Version')->searchable()->sortable(),
            TextColumn::make('correlation_id')->label('Correlation Id')->searchable()->sortable(),
            TextColumn::make('occurred_at')->label('Occurred At')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentExecutionEventRecords::route('/'),
        ];
    }
}
