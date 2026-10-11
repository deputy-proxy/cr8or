<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use App\Filament\Resources\Expenses\ExpenseResource;
use Filament\Resources\Pages\EditRecord;

class EditExpenses extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = ExpenseResource::class;
}
