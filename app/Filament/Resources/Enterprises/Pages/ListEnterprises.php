<?php

namespace App\Filament\Resources\Enterprises\Pages;

use App\Filament\Resources\Enterprises\EnterpriseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEnterprises extends ListRecords
{
    protected static string $resource = EnterpriseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New'),
        ];
    }
}

