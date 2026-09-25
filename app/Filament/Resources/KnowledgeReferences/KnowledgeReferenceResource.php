<?php

namespace App\Filament\Resources\KnowledgeReferences;

use App\Filament\Resources\Concerns\ScopesKnowledgeRecords;
use App\Filament\Resources\KnowledgeReferences\Pages\CreateKnowledgeReference;
use App\Filament\Resources\KnowledgeReferences\Pages\EditKnowledgeReference;
use App\Filament\Resources\KnowledgeReferences\Pages\ListKnowledgeReferences;
use App\Filament\Resources\KnowledgeReferences\Schemas\KnowledgeReferenceForm;
use App\Filament\Resources\KnowledgeReferences\Tables\KnowledgeReferencesTable;
use App\Models\KnowledgeReference;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class KnowledgeReferenceResource extends Resource
{
    use ScopesKnowledgeRecords;

    protected static ?string $model = KnowledgeReference::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|\UnitEnum|null $navigationGroup = 'Intelligence';

    protected static ?string $navigationLabel = 'Knowledge References';

    protected static ?int $navigationSort = 80;

    public static function form(Schema $schema): Schema
    {
        return KnowledgeReferenceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return KnowledgeReferencesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeReferences::route('/'),
            'create' => CreateKnowledgeReference::route('/create'),
            'edit' => EditKnowledgeReference::route('/{record}/edit'),
        ];
    }
}