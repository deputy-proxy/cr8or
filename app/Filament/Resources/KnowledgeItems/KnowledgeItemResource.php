<?php

namespace App\Filament\Resources\KnowledgeItems;

use App\Filament\Resources\Concerns\ScopesKnowledgeRecords;
use App\Filament\Resources\KnowledgeItems\Pages\CreateKnowledgeItem;
use App\Filament\Resources\KnowledgeItems\Pages\EditKnowledgeItem;
use App\Filament\Resources\KnowledgeItems\Pages\ListKnowledgeItems;
use App\Filament\Resources\KnowledgeItems\Schemas\KnowledgeItemForm;
use App\Filament\Resources\KnowledgeItems\Tables\KnowledgeItemsTable;
use App\Models\KnowledgeItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class KnowledgeItemResource extends Resource
{
    use ScopesKnowledgeRecords;

    protected static ?string $model = KnowledgeItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|\UnitEnum|null $navigationGroup = 'Intelligence';

    protected static ?string $navigationLabel = 'Knowledge Items';

    protected static ?int $navigationSort = 70;

    public static function form(Schema $schema): Schema
    {
        return KnowledgeItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return KnowledgeItemsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeItems::route('/'),
            'create' => CreateKnowledgeItem::route('/create'),
            'edit' => EditKnowledgeItem::route('/{record}/edit'),
        ];
    }
}