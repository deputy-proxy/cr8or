<?php

namespace App\Filament\Resources\CommandWebhookDeliveries;

use App\Filament\Resources\CommandWebhookDeliveries\Pages\ListCommandWebhookDeliveries;
use App\Models\CommandWebhookDelivery;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CommandWebhookDeliveryResource extends Resource
{
    protected static ?string $model = CommandWebhookDelivery::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'CommandWebhookDeliveries';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCommandWebhookDeliveries::route('/'),
        ];
    }
}
