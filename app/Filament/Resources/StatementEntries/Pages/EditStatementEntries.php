<?php

namespace App\Filament\Resources\StatementEntries\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use App\Filament\Resources\StatementEntries\StatementEntryResource;
use Filament\Resources\Pages\EditRecord;

class EditStatementEntries extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = StatementEntryResource::class;
}
