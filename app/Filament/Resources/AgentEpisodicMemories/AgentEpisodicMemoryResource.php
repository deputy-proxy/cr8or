<?php

namespace App\Filament\Resources\AgentEpisodicMemories;

use App\Filament\Resources\AgentEpisodicMemories\Pages\ListAgentEpisodicMemories;
use App\Models\AgentEpisodicMemory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AgentEpisodicMemoryResource extends Resource
{
    protected static ?string $model = AgentEpisodicMemory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Agentic Flow';

    protected static ?int $navigationSort = 100;

    protected static ?string $navigationLabel = 'Agent Episodic Memories';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('agentDescriptor.slug')->label('Agent')->searchable()->sortable(),
            TextColumn::make('enterprise.name')->label('Enterprise')->searchable()->sortable(),
            TextColumn::make('topic')->label('Topic')->searchable()->sortable(),
            TextColumn::make('objective')->label('Objective')->searchable()->sortable(),
            TextColumn::make('outcome')->label('Outcome')->searchable()->sortable(),
            TextColumn::make('occurred_at')->label('Occurred At')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentEpisodicMemories::route('/'),
        ];
    }
}
