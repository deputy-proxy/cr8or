<?php

namespace App\Filament\Resources\Plans\Pages;

use App\Filament\Resources\Plans\PlanResource;
use App\Models\Plan;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;

class EditPlan extends EditRecord
{
    protected static string $resource = PlanResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Plan $record */
        $record = $this->record;

        if ((int) $data['strategy_id'] !== $record->strategy_id) {
            throw new AuthorizationException('Cannot reassign this record.');
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}

