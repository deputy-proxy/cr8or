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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Knowledge Management';
    protected static ?int $navigationSort = 100;

    protected static ?string $navigationLabel = 'KnowledgeEmbeddings';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeEmbeddings::route('/'),
        ];
    }
}
