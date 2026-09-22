<?php

namespace App\Filament\Resources\KnowledgeSpecifications;

use App\Filament\Resources\Concerns\ScopesKnowledgeRecords;
use App\Filament\Resources\KnowledgeSpecifications\Pages\CreateKnowledgeSpecification;
use App\Filament\Resources\KnowledgeSpecifications\Pages\EditKnowledgeSpecification;
use App\Filament\Resources\KnowledgeSpecifications\Pages\ListKnowledgeSpecifications;
use App\Filament\Resources\KnowledgeSpecifications\Schemas\KnowledgeSpecificationForm;
use App\Filament\Resources\KnowledgeSpecifications\Tables\KnowledgeSpecificationsTable;
use App\Models\KnowledgeSpecification;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class KnowledgeSpecificationResource extends Resource
{
    use ScopesKnowledgeRecords;

    protected static ?string $model = KnowledgeSpecification::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    public static function form(Schema $schema): Schema
    {
        return KnowledgeSpecificationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return KnowledgeSpecificationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeSpecifications::route('/'),
            'create' => CreateKnowledgeSpecification::route('/create'),
            'edit' => EditKnowledgeSpecification::route('/{record}/edit'),
        ];
    }
}
