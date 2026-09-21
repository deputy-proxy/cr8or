<?php

namespace App\Filament\Resources\Goals\Pages;

use App\Filament\Resources\Goals\GoalResource;
use App\Models\Enterprise;
use App\Models\Goal;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;

class CreateGoal extends CreateRecord
{
    protected static string $resource = GoalResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $parent = Enterprise::query()->findOrFail((int) $data['enterprise_id']);
        Gate::authorize('create', [Goal::class, $parent]);

        return $data;
    }
}
