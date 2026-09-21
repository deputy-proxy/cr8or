<?php

namespace App\Filament\Resources\EnterpriseDecisions\Pages;

use App\Filament\Resources\EnterpriseDecisions\EnterpriseDecisionResource;
use App\Models\Enterprise;
use App\Models\EnterpriseDecision;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;

class CreateEnterpriseDecision extends CreateRecord
{
    protected static string $resource = EnterpriseDecisionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $parent = Enterprise::query()->findOrFail((int) $data['enterprise_id']);
        Gate::authorize('create', [EnterpriseDecision::class, $parent]);
        $actor = \App\Models\User::query()->findOrFail((int) $data['actor_id']);
        $data['actor_name'] = $actor->name;

        return $data;
    }
}
