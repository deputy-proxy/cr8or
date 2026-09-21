<?php

namespace App\Filament\Resources\Memberships\Pages;

use App\Filament\Resources\Memberships\MembershipResource;
use App\Models\Membership;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;

class EditMembership extends EditRecord
{
    protected static string $resource = MembershipResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Membership $record */
        $record = $this->record;
        if ((int) $data['organization_id'] !== $record->organization_id) {
            throw new AuthorizationException('Cannot reassign this record.');
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
