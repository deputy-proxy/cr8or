<?php

namespace App\Filament\Resources\Budgets\Pages;

use App\Filament\Resources\Budgets\BudgetResource;
use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use Filament\Resources\Pages\EditRecord;

class EditBudgets extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = BudgetResource::class;
}
