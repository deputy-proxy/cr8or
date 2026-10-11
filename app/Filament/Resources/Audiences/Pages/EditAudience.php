<?php

namespace App\Filament\Resources\Audiences\Pages;

use App\Filament\Resources\Audiences\AudienceResource;
use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAudience extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = AudienceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
