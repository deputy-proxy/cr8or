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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Knowledge Management';

    protected static ?int $navigationSort = 80;

    protected static ?string $navigationLabel = 'Knowledge Index Records';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('source.name')->label('Source')->searchable()->sortable(),
            TextColumn::make('document.title')->label('Document')->searchable()->sortable(),
            TextColumn::make('item.title')->label('Knowledge Item')->searchable()->sortable(),
            TextColumn::make('unit_key')->label('Unit Key')->searchable()->sortable(),
            TextColumn::make('representation_key')->label('Representation Key')->searchable()->sortable(),
            TextColumn::make('status')->label('Status')->searchable()->sortable(),
            TextColumn::make('provider')->label('Provider')->searchable()->sortable(),
            TextColumn::make('indexed_at')->label('Indexed At')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeIndexRecords::route('/'),
        ];
    }
}
