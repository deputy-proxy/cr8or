<?php

namespace App\Filament\Resources\MarketingStrategies\Pages;

use App\Filament\Resources\MarketingStrategies\MarketingStrategyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMarketingStrategies extends ListRecords
{
    protected static string $resource = MarketingStrategyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}