<?php

namespace App\Filament\Resources\Initiatives\Pages;

use App\Filament\Resources\Initiatives\InitiativeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInitiatives extends ListRecords
{
    protected static string $resource = InitiativeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

