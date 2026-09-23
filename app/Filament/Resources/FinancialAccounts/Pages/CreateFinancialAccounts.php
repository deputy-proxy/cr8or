<?php

namespace App\Filament\Resources\FinancialAccounts\Pages;

use App\Filament\Resources\FinancialAccounts\FinancialAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFinancialAccounts extends CreateRecord
{
    protected static string $resource = FinancialAccountResource::class;
}