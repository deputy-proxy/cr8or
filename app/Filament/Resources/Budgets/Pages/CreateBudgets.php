<?php

namespace App\Filament\Resources\Budgets\Pages;

use App\Filament\Resources\Budgets\BudgetResource;
use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use Filament\Resources\Pages\CreateRecord;

class CreateBudgets extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected static string $resource = BudgetResource::class;
}
