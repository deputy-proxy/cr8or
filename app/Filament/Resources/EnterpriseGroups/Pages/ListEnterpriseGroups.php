<?php

namespace App\Filament\Resources\EnterpriseGroups\Pages;

use App\Filament\Resources\EnterpriseGroups\EnterpriseGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEnterpriseGroups extends ListRecords
{
    protected static string $resource = EnterpriseGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
