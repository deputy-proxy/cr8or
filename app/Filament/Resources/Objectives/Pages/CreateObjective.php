<?php

namespace App\Filament\Resources\Objectives\Pages;

use App\Filament\Resources\Objectives\ObjectiveResource;
use App\Models\Enterprise;
use App\Models\Goal;
use App\Models\Kpi;
use App\Models\Objective;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class CreateObjective extends CreateRecord
{
    protected static string $resource = ObjectiveResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $enterprise = Enterprise::query()->findOrFail((int) $data['enterprise_id']);
        Gate::authorize('createForEnterprise', [Objective::class, $enterprise]);

        $this->validateReferenceOwnership($data, $enterprise);

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function validateReferenceOwnership(array $data, Enterprise $enterprise): void
    {
        if (isset($data['goal_id']) && ! Goal::query()->whereKey($data['goal_id'])->where('enterprise_id', $enterprise->getKey())->exists()) {
            throw new AuthorizationException('Goal must belong to the selected enterprise.');
        }

        if (isset($data['kpi_id']) && ! Kpi::query()->whereKey($data['kpi_id'])->where('enterprise_id', $enterprise->getKey())->exists()) {
            throw new AuthorizationException('KPI must belong to the selected enterprise.');
        }
    }
}