<?php

namespace App\Filament\Resources\FinancialPeriods\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use App\Filament\Resources\FinancialPeriods\FinancialPeriodResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFinancialPeriods extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected static string $resource = FinancialPeriodResource::class;
}
