<?php

namespace App\Filament\Resources\EnterpriseContexts\Pages;

use App\Filament\Resources\EnterpriseContexts\EnterpriseContextResource;
use App\Models\Enterprise;
use App\Models\EnterpriseContext;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;

class CreateEnterpriseContext extends CreateRecord
{
    protected static string $resource = EnterpriseContextResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $parent = Enterprise::query()->findOrFail((int) $data['enterprise_id']);
        Gate::authorize('create', [EnterpriseContext::class, $parent]);

        return $data;
    }
}
