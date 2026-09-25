<?php

namespace App\Filament\Resources\StatementEntries\Pages;

use App\Filament\Resources\StatementEntries\StatementEntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStatementEntries extends ListRecords
{
    protected static string $resource = StatementEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New'),
        ];
    }
}

