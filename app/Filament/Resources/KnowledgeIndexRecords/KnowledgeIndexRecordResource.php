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

    protected static ?string $navigationLabel = 'KnowledgeIndexRecords';

    protected static ?int $navigationSort = 80;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeIndexRecords::route('/'),
        ];
    }
}
