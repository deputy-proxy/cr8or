<?php

namespace App\Filament\Resources\MarketingStrategies\Pages;

use App\Filament\Resources\MarketingStrategies\MarketingStrategyResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMarketingStrategy extends EditRecord
{
    protected static string $resource = MarketingStrategyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}