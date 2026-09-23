<?php

namespace App\Filament\Resources\TransactionCategories\Pages;

use App\Filament\Resources\TransactionCategories\TransactionCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTransactionCategories extends CreateRecord
{
    protected static string $resource = TransactionCategoryResource::class;
}