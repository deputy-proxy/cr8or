<?php

namespace App\Filament\Resources\IntegrationResults;

use App\Filament\Resources\IntegrationResults\Pages\ListIntegrationResults;
use App\Models\IntegrationResult;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IntegrationResultResource extends Resource
{
    protected static ?string $model = IntegrationResult::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations & External Systems';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'IntegrationResults';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIntegrationResults::route('/'),
        ];
    }
}
