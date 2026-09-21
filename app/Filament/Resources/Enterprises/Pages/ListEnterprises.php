<?php

namespace App\Filament\Resources\Enterprises\Pages;

use App\Filament\Resources\Enterprises\EnterpriseResource;
use Filament\Resources\Pages\ListRecords;

class ListEnterprises extends ListRecords
{
    protected static string $resource = EnterpriseResource::class;
}
