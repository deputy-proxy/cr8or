<?php

namespace App\Filament\Resources\StatementEntries\Pages;

use App\Filament\Resources\StatementEntries\StatementEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListStatementEntries extends ListRecords
{
    protected static string $resource = StatementEntryResource::class;
}
