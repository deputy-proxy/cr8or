<?php

namespace App\Filament\Resources\Strategies\Pages;

use App\Filament\Resources\Strategies\StrategyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStrategies extends ListRecords
{
    protected static string $resource = StrategyResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
