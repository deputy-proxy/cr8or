<?php

namespace App\Filament\Resources\Transactions\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use App\Filament\Resources\Transactions\TransactionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTransactions extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected static string $resource = TransactionResource::class;
}
