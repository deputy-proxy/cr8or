<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInvoices extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected static string $resource = InvoiceResource::class;
}
