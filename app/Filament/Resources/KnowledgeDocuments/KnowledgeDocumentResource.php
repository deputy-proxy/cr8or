<?php

namespace App\Filament\Resources\KnowledgeDocuments;

use App\Filament\Resources\Concerns\ScopesKnowledgeRecords;
use App\Filament\Resources\KnowledgeDocuments\Pages\CreateKnowledgeDocument;
use App\Filament\Resources\KnowledgeDocuments\Pages\EditKnowledgeDocument;
use App\Filament\Resources\KnowledgeDocuments\Pages\ListKnowledgeDocuments;
use App\Filament\Resources\KnowledgeDocuments\Schemas\KnowledgeDocumentForm;
use App\Filament\Resources\KnowledgeDocuments\Tables\KnowledgeDocumentsTable;
use App\Models\KnowledgeDocument;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class KnowledgeDocumentResource extends Resource
{
    use ScopesKnowledgeRecords;

    protected static ?string $model = KnowledgeDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|\UnitEnum|null $navigationGroup = 'Intelligence';

    protected static ?string $navigationLabel = 'Knowledge Documents';

    protected static ?int $navigationSort = 60;

    public static function form(Schema $schema): Schema
    {
        return KnowledgeDocumentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return KnowledgeDocumentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeDocuments::route('/'),
            'create' => CreateKnowledgeDocument::route('/create'),
            'edit' => EditKnowledgeDocument::route('/{record}/edit'),
        ];
    }
}
