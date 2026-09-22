<?php

namespace App\Filament\Resources\Milestones\Pages;

use App\Filament\Resources\Milestones\MilestoneResource;
use App\Models\Milestone;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;

class EditMilestone extends EditRecord
{
    protected static string $resource = MilestoneResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Milestone $record */
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
