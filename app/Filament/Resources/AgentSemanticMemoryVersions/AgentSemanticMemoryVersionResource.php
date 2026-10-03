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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Agentic Flow';

    protected static ?string $navigationLabel = 'Agent SemanticMemoryVersions';

    protected static ?int $navigationSort = 120;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentSemanticMemoryVersions::route('/'),
        ];
    }
}
