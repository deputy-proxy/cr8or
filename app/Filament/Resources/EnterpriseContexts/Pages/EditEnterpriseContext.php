<?php

namespace App\Filament\Resources\EnterpriseContexts\Pages;

use App\Filament\Resources\EnterpriseContexts\EnterpriseContextResource;
use App\Models\EnterpriseContext;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;

class EditEnterpriseContext extends EditRecord
{
    protected static string $resource = EnterpriseContextResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var EnterpriseContext $record */
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
