<?php

namespace App\Filament\Resources\KnowledgeIndexRecords;

use App\Filament\Resources\KnowledgeIndexRecords\Pages\ListKnowledgeIndexRecords;
use App\Models\KnowledgeIndexRecord;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class KnowledgeIndexRecordResource extends Resource
{
    protected static ?string $model = KnowledgeIndexRecord::class;

    protected static ?string $modelLabel = 'Knowledge Index Record';

    protected static ?string $pluralModelLabel = 'Knowledge Index Records';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Knowledge';

    protected static ?string $navigationLabel = 'Knowledge Index Records';

    protected static ?int $navigationSort = 80;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('knowledge_source_id')->label('Source')->searchable()->sortable(),
            TextColumn::make('knowledge_document_id')->label('Document')->searchable()->sortable(),
            TextColumn::make('knowledge_item_id')->label('Item')->searchable()->sortable(),
            TextColumn::make('knowledge_version_id')->label('Version')->searchable()->sortable(),
            TextColumn::make('unit_key')->searchable()->sortable(),
            TextColumn::make('representation_key')->searchable()->sortable(),
            TextColumn::make('status')->badge()->searchable()->sortable(),
            TextColumn::make('provider')->searchable()->sortable(),
            TextColumn::make('indexed_at')->dateTime()->sortable(),
            TextColumn::make('invalidated_at')->dateTime()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeIndexRecords::route('/'),
        ];
    }
}
