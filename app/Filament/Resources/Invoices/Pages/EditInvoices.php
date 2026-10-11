<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Resources\Pages\EditRecord;

class EditInvoices extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = InvoiceResource::class;
}
