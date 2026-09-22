<?php

namespace App\Filament\Resources\KnowledgeContexts;

use App\Filament\Resources\Concerns\ScopesKnowledgeRecords;
use App\Filament\Resources\KnowledgeContexts\Pages\CreateKnowledgeContext;
use App\Filament\Resources\KnowledgeContexts\Pages\EditKnowledgeContext;
use App\Filament\Resources\KnowledgeContexts\Pages\ListKnowledgeContexts;
use App\Filament\Resources\KnowledgeContexts\Schemas\KnowledgeContextForm;
use App\Filament\Resources\KnowledgeContexts\Tables\KnowledgeContextsTable;
use App\Models\KnowledgeContext;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class KnowledgeContextResource extends Resource
{
    use ScopesKnowledgeRecords;

    protected static ?string $model = KnowledgeContext::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    public static function form(Schema $schema): Schema
    {
        return KnowledgeContextForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return KnowledgeContextsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeContexts::route('/'),
            'create' => CreateKnowledgeContext::route('/create'),
            'edit' => EditKnowledgeContext::route('/{record}/edit'),
        ];
    }
}