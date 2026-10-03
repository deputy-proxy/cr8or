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

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations & External Systems';

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Command Webhook Deliveries';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('capability')->label('Capability')->searchable()->sortable(),
            TextColumn::make('status')->label('Status')->searchable()->sortable(),
            TextColumn::make('failure_code')->label('Failure Code')->searchable()->sortable(),
            TextColumn::make('correlation_id')->label('Correlation Id')->searchable()->sortable(),
            TextColumn::make('processed_at')->label('Processed At')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCommandWebhookDeliveries::route('/'),
        ];
    }
}
