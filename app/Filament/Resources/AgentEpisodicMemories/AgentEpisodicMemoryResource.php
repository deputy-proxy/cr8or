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

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Agent EpisodicMemories';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentEpisodicMemories::route('/'),
        ];
    }
}
