<?php

namespace App\Filament\Resources\EnterpriseContexts\Pages;

use App\Filament\Resources\EnterpriseContexts\EnterpriseContextResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEnterpriseContexts extends ListRecords
{
    protected static string $resource = EnterpriseContextResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

