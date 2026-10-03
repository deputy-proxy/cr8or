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

    protected static ?string $modelLabel = 'Command Webhook Delivery';

    protected static ?string $pluralModelLabel = 'Command Webhook Deliveries';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations & External Systems';

    protected static ?string $navigationLabel = 'Command Webhook Deliveries';

    protected static ?int $navigationSort = 50;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('capability')->searchable()->sortable(),
            TextColumn::make('idempotency_key')->label('Idempotency Key')->searchable(),
            TextColumn::make('status')->badge()->searchable()->sortable(),
            TextColumn::make('failure_code')->badge()->searchable(),
            TextColumn::make('processed_at')->dateTime()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCommandWebhookDeliveries::route('/'),
        ];
    }
}
