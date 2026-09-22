<?php

namespace App\Filament\Resources\KnowledgeVersions;

use App\Filament\Resources\Concerns\ScopesKnowledgeRecords;
use App\Filament\Resources\KnowledgeVersions\Pages\CreateKnowledgeVersion;
use App\Filament\Resources\KnowledgeVersions\Pages\ListKnowledgeVersions;
use App\Filament\Resources\KnowledgeVersions\Schemas\KnowledgeVersionForm;
use App\Filament\Resources\KnowledgeVersions\Tables\KnowledgeVersionsTable;
use App\Models\KnowledgeVersion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class KnowledgeVersionResource extends Resource
{
    use ScopesKnowledgeRecords;

    protected static ?string $model = KnowledgeVersion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    public static function form(Schema $schema): Schema
    {
        return KnowledgeVersionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return KnowledgeVersionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeVersions::route('/'),
            'create' => CreateKnowledgeVersion::route('/create'),
        ];
    }
}
