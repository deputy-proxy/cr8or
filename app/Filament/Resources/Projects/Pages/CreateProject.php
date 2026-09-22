<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Enterprise;
use App\Models\Initiative;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Strategy;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateProject extends CreateRecord
{
    protected static string $resource = ProjectResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $enterprise = Enterprise::query()->findOrFail((int) $data['enterprise_id']);
        Gate::authorize('create', [Project::class, $enterprise]);

        if (! empty($data['strategy_id'])) {
            /** @var Strategy $strategy */
            $strategy = Strategy::query()->findOrFail((int) $data['strategy_id']);
            if ($strategy->objective->enterprise_id !== $enterprise->id) {
                throw ValidationException::withMessages(['strategy_id' => 'The strategy must belong to the selected enterprise.']);
            }
        }

        if (! empty($data['plan_id'])) {
            /** @var Plan $plan */
            $plan = Plan::query()->findOrFail((int) $data['plan_id']);
            if ($plan->strategy->objective->enterprise_id !== $enterprise->id) {
                throw ValidationException::withMessages(['plan_id' => 'The plan must belong to the selected enterprise.']);
            }
        }

        if (! empty($data['initiative_id'])) {
            /** @var Initiative $initiative */
            $initiative = Initiative::query()->findOrFail((int) $data['initiative_id']);
            if ($initiative->plan->strategy->objective->enterprise_id !== $enterprise->id) {
                throw ValidationException::withMessages(['initiative_id' => 'The initiative must belong to the selected enterprise.']);
            }
        }

        return $data;
    }
}
