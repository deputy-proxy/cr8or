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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'KnowledgeIndexUnits';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeIndexUnits::route('/'),
        ];
    }
}
