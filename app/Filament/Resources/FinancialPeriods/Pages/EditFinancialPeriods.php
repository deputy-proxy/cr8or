<?php

namespace App\Filament\Resources\FinancialPeriods\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use App\Filament\Resources\FinancialPeriods\FinancialPeriodResource;
use Filament\Resources\Pages\EditRecord;

class EditFinancialPeriods extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = FinancialPeriodResource::class;
}
