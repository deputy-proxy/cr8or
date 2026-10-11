<?php

namespace App\Filament\Resources\Statements\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use App\Filament\Resources\Statements\StatementResource;
use Filament\Resources\Pages\EditRecord;

class EditStatements extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = StatementResource::class;
}
