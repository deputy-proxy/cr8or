<?php

namespace App\Filament\Resources\AgentSemanticMemoryVersions;

use App\Filament\Resources\AgentSemanticMemoryVersions\Pages\ListAgentSemanticMemoryVersions;
use App\Models\AgentSemanticMemoryVersion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AgentSemanticMemoryVersionResource extends Resource
{
    protected static ?string $model = AgentSemanticMemoryVersion::class;

    protected static ?string $modelLabel = 'Agent Semantic Memory Version';

    protected static ?string $pluralModelLabel = 'Agent Semantic Memory Versions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Agentic Flow';

    protected static ?string $navigationLabel = 'Agent Semantic Memory Versions';

    protected static ?int $navigationSort = 120;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('agent_semantic_memory_id')->label('Memory')->searchable()->sortable(),
            TextColumn::make('agentDescriptor.slug')->label('Agent')->searchable()->sortable(),
            TextColumn::make('statement')->limit(100)->searchable(),
            TextColumn::make('confidence')->numeric()->sortable(),
            TextColumn::make('status')->badge()->searchable()->sortable(),
            TextColumn::make('change_type')->badge()->searchable()->sortable(),
            TextColumn::make('recorded_at')->dateTime()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentSemanticMemoryVersions::route('/'),
        ];
    }
}
