<?php

namespace App\Filament\Resources\Statements\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use App\Filament\Resources\Statements\StatementResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStatements extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected static string $resource = StatementResource::class;
}
