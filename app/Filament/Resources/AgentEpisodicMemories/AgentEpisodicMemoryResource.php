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

    protected static ?string $modelLabel = 'Agent Episodic Memory';

    protected static ?string $pluralModelLabel = 'Agent Episodic Memories';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Agentic Flow';

    protected static ?string $navigationLabel = 'Agent Episodic Memories';

    protected static ?int $navigationSort = 100;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('agentDescriptor.slug')->label('Agent')->searchable()->sortable(),
            TextColumn::make('topic')->searchable()->sortable(),
            TextColumn::make('objective')->limit(60),
            TextColumn::make('outcome')->limit(60),
            TextColumn::make('occurred_at')->dateTime()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentEpisodicMemories::route('/'),
        ];
    }
}
