<?php

namespace App\Filament\Resources\Strategies\Pages;

use App\Filament\Resources\Strategies\StrategyResource;
use App\Models\Objective;
use App\Models\Strategy;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;

class CreateStrategy extends CreateRecord
{
    protected static string $resource = StrategyResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $objective = Objective::query()->with('enterprise')->findOrFail((int) $data['objective_id']);
        Gate::authorize('createForObjective', [Strategy::class, $objective]);

        return $data;
    }
}