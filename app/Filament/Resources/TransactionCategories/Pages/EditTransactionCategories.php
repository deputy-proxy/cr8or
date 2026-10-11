<?php

namespace App\Filament\Resources\TransactionCategories\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use App\Filament\Resources\TransactionCategories\TransactionCategoryResource;
use Filament\Resources\Pages\EditRecord;

class EditTransactionCategories extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = TransactionCategoryResource::class;
}
