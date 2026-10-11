<?php

namespace App\Filament\Resources\Transactions\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use App\Filament\Resources\Transactions\TransactionResource;
use Filament\Resources\Pages\EditRecord;

class EditTransactions extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = TransactionResource::class;
}
