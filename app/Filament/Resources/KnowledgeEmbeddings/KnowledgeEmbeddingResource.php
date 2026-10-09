<?php

namespace App\Filament\Resources\KnowledgeEmbeddings;

use App\Filament\Resources\KnowledgeEmbeddings\Pages\ListKnowledgeEmbeddings;
use App\Models\KnowledgeEmbedding;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class KnowledgeEmbeddingResource extends Resource
{
    protected static ?string $model = KnowledgeEmbedding::class;

    protected static ?string $modelLabel = 'Knowledge Embedding';

    protected static ?string $pluralModelLabel = 'Knowledge Embeddings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Knowledge';

    protected static ?string $navigationLabel = 'Knowledge Embeddings';

    protected static ?int $navigationSort = 100;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('knowledge_index_unit_id')->label('Index Unit')->searchable()->sortable(),
            TextColumn::make('knowledge_index_record_id')->label('Index Record')->searchable()->sortable(),
            TextColumn::make('knowledge_version_id')->label('Knowledge Version')->searchable()->sortable(),
            TextColumn::make('embedding_version')->searchable()->sortable(),
            TextColumn::make('content_hash')->label('Content Hash')->searchable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeEmbeddings::route('/'),
        ];
    }
}
