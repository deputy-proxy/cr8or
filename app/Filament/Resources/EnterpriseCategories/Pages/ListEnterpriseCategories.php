<?php

namespace App\Filament\Resources\EnterpriseCategories\Pages;

use App\Filament\Resources\EnterpriseCategories\EnterpriseCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEnterpriseCategories extends ListRecords
{
    protected static string $resource = EnterpriseCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
