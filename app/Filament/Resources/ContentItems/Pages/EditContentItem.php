<?php

namespace App\Filament\Resources\ContentItems\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use App\Filament\Resources\ContentItems\ContentItemResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditContentItem extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = ContentItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
