<?php

namespace App\Filament\Resources\KnowledgeIndexUnits;

use App\Filament\Resources\KnowledgeIndexUnits\Pages\ListKnowledgeIndexUnits;
use App\Models\KnowledgeIndexUnit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class KnowledgeIndexUnitResource extends Resource
{
    protected static ?string $model = KnowledgeIndexUnit::class;

    protected static ?string $modelLabel = 'Knowledge Index Unit';

    protected static ?string $pluralModelLabel = 'Knowledge Index Units';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Knowledge Management';

    protected static ?string $navigationLabel = 'Knowledge Index Units';

    protected static ?int $navigationSort = 90;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('knowledge_index_record_id')->label('Index Record')->searchable()->sortable(),
            TextColumn::make('knowledge_item_id')->label('Item')->searchable()->sortable(),
            TextColumn::make('knowledge_version_id')->label('Version')->searchable()->sortable(),
            TextColumn::make('unit_key')->searchable()->sortable(),
            TextColumn::make('ordinal')->numeric()->sortable(),
            TextColumn::make('content')->limit(100)->searchable(),
            TextColumn::make('heading_path')->searchable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeIndexUnits::route('/'),
        ];
    }
}
