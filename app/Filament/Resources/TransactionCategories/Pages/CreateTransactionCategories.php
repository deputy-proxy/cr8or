<?php

namespace App\Filament\Resources\TransactionCategories\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use App\Filament\Resources\TransactionCategories\TransactionCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTransactionCategories extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected static string $resource = TransactionCategoryResource::class;
}
