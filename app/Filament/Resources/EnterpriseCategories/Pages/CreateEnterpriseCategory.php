<?php

namespace App\Filament\Resources\EnterpriseCategories\Pages;

use App\Filament\Resources\EnterpriseCategories\EnterpriseCategoryResource;
use App\Models\EnterpriseCategory;
use App\Models\Organization;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;

class CreateEnterpriseCategory extends CreateRecord
{
    protected static string $resource = EnterpriseCategoryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $organization = Organization::query()->findOrFail((int) $data['organization_id']);
        Gate::authorize('createForOrganization', [EnterpriseCategory::class, $organization]);

        return $data;
    }
}
