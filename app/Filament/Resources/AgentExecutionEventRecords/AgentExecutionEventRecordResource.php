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

    protected static ?string $navigationLabel = 'Agent Execution EventRecords';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentExecutionEventRecords::route('/'),
        ];
    }
}
