<?php

namespace App\Filament\Resources\Plans\Pages;

use App\Filament\Resources\Plans\PlanResource;
use App\Models\Plan;
use App\Models\Strategy;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;

class CreatePlan extends CreateRecord
{
    protected static string $resource = PlanResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $strategy = Strategy::query()->with('objective.enterprise')->findOrFail((int) $data['strategy_id']);
        Gate::authorize('createForStrategy', [Plan::class, $strategy]);

        return $data;
    }
}
