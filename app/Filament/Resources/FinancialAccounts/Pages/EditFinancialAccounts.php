<?php

namespace App\Filament\Resources\FinancialAccounts\Pages;

use App\Filament\Resources\FinancialAccounts\FinancialAccountResource;
use Filament\Resources\Pages\EditRecord;

class EditFinancialAccounts extends EditRecord
{
    protected static string $resource = FinancialAccountResource::class;
}