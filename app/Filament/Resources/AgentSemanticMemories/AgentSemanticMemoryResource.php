<?php

namespace App\Filament\Resources\AgentSemanticMemories;

use App\Filament\Resources\AgentSemanticMemories\Pages\ListAgentSemanticMemories;
use App\Models\AgentSemanticMemory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AgentSemanticMemoryResource extends Resource
{
    protected static ?string $model = AgentSemanticMemory::class;

    protected static ?string $modelLabel = 'Agent Semantic Memory';

    protected static ?string $pluralModelLabel = 'Agent Semantic Memories';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Agent Operations & Governance';

    protected static ?string $navigationLabel = 'Agent Semantic Memories';

    protected static ?int $navigationSort = 100;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('agentDescriptor.slug')->label('Agent')->searchable()->sortable(),
            TextColumn::make('statement')->limit(100)->searchable(),
            TextColumn::make('confidence')->numeric()->sortable(),
            TextColumn::make('status')->badge()->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentSemanticMemories::route('/'),
        ];
    }
}
