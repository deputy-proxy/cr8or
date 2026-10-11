<?php

namespace App\Filament\Resources\Scripts\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use App\Filament\Resources\Scripts\ScriptResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditScript extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = ScriptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
