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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Agentic Flow';

    protected static ?int $navigationSort = 110;

    protected static ?string $navigationLabel = 'Agent Semantic Memories';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('agentDescriptor.slug')->label('Agent')->searchable()->sortable(),
            TextColumn::make('enterprise.name')->label('Enterprise')->searchable()->sortable(),
            TextColumn::make('statement')->label('Statement')->searchable()->sortable(),
            TextColumn::make('confidence')->label('Confidence')->searchable()->sortable(),
            TextColumn::make('status')->label('Status')->searchable()->sortable(),
            TextColumn::make('updated_at')->label('Updated At')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentSemanticMemories::route('/'),
        ];
    }
}
