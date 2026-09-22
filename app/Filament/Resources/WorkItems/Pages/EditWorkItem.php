<?php

namespace App\Filament\Resources\WorkItems\Pages;

use App\Filament\Resources\WorkItems\WorkItemResource;
use App\Models\WorkItem;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;

class EditWorkItem extends EditRecord
{
    protected static string $resource = WorkItemResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var WorkItem $record */
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
