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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Agent Execution Steps';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentExecutionSteps::route('/'),
        ];
    }
}
