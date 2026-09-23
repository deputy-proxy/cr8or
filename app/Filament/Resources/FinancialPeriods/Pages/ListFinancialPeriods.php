<?php

namespace App\Filament\Resources\FinancialPeriods\Pages;

use App\Filament\Resources\FinancialPeriods\FinancialPeriodResource;
use Filament\Resources\Pages\ListRecords;

class ListFinancialPeriods extends ListRecords
{
    protected static string $resource = FinancialPeriodResource::class;
}