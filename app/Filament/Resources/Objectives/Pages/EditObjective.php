<?php

namespace App\Filament\Resources\Objectives\Pages;

use App\Filament\Resources\Objectives\ObjectiveResource;
use App\Models\Enterprise;
use App\Models\Goal;
use App\Models\Kpi;
use App\Models\Objective;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;

class EditObjective extends EditRecord
{
    protected static string $resource = ObjectiveResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Objective $record */
        $record = $this->record;

        if ((int) $data['enterprise_id'] !== $record->enterprise_id) {
            throw new AuthorizationException('Cannot reassign this record.');
        }

        $enterprise = Enterprise::query()->findOrFail($record->enterprise_id);

        if (isset($data['goal_id']) && ! Goal::query()->whereKey($data['goal_id'])->where('enterprise_id', $enterprise->getKey())->exists()) {
            throw new AuthorizationException('Goal must belong to the selected enterprise.');
        }

        if (isset($data['kpi_id']) && ! Kpi::query()->whereKey($data['kpi_id'])->where('enterprise_id', $enterprise->getKey())->exists()) {
            throw new AuthorizationException('KPI must belong to the selected enterprise.');
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}

