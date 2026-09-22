<?php

namespace App\Filament\Resources\Initiatives\Pages;

use App\Filament\Resources\Initiatives\InitiativeResource;
use App\Models\Initiative;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;

class EditInitiative extends EditRecord
{
    protected static string $resource = InitiativeResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Initiative $record */
        $record = $this->record;

        if ((int) $data['plan_id'] !== $record->plan_id) {
            throw new AuthorizationException('Cannot reassign this record.');
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}