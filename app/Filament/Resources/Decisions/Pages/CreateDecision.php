<?php

namespace App\Filament\Resources\Decisions\Pages;

use App\Filament\Resources\Decisions\DecisionResource;
use App\Models\Decision;
use App\Models\Enterprise;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;

class CreateDecision extends CreateRecord
{
    protected static string $resource = DecisionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $enterprise = Enterprise::query()->findOrFail((int) $data['enterprise_id']);
        Gate::authorize('createForEnterprise', [Decision::class, $enterprise]);

        $actor = User::query()->findOrFail((int) $data['actor_id']);
        $data['actor_name'] = $actor->name;

        return $data;
    }
}