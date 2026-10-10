<?php

namespace App\Filament\Resources\EnterpriseGroups\Pages;

use App\Filament\Resources\EnterpriseGroups\EnterpriseGroupResource;
use App\Models\EnterpriseGroup;
use App\Models\Organization;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;

class CreateEnterpriseGroup extends CreateRecord
{
    protected static string $resource = EnterpriseGroupResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $organization = Organization::query()->findOrFail((int) $data['organization_id']);
        Gate::authorize('createForOrganization', [EnterpriseGroup::class, $organization]);

        return $data;
    }
}
