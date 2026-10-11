<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use App\Filament\Resources\Expenses\ExpenseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateExpenses extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected static string $resource = ExpenseResource::class;
}
