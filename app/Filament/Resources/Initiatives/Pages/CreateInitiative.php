<?php

namespace App\Filament\Resources\Initiatives\Pages;

use App\Filament\Resources\Initiatives\InitiativeResource;
use App\Models\Initiative;
use App\Models\Plan;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;

class CreateInitiative extends CreateRecord
{
    protected static string $resource = InitiativeResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $plan = Plan::query()->with('strategy.objective.enterprise')->findOrFail((int) $data['plan_id']);
        Gate::authorize('create', [Initiative::class, $plan]);

        return $data;
    }
}

