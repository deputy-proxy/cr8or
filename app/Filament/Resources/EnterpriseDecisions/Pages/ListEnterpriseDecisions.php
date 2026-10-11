<?php

namespace App\Filament\Resources\EnterpriseDecisions\Pages;

use App\Filament\Resources\EnterpriseDecisions\EnterpriseDecisionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEnterpriseDecisions extends ListRecords
{
    protected static string $resource = EnterpriseDecisionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}