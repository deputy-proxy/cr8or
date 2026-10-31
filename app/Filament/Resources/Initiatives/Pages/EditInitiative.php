<?php

namespace App\Filament\Resources\Initiatives\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use App\Filament\Resources\Initiatives\InitiativeResource;
use App\Models\Initiative;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;

class EditInitiative extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = InitiativeResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Initiative $record */
        $record = $this->record;

        if ((int) $data['enterprise_id'] !== $record->enterprise_id) {
            throw new AuthorizationException('Cannot reassign this record.');
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
