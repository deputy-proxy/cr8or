<?php

namespace App\Filament\Resources\Enterprises\Pages;

use App\Filament\Resources\Enterprises\EnterpriseResource;
use App\Models\Enterprise;
use App\Models\Organization;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;

class CreateEnterprise extends CreateRecord
{
    protected static string $resource = EnterpriseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $parent = Organization::query()->findOrFail((int) $data['organization_id']);
        Gate::authorize('create', [Enterprise::class, $parent]);

        return $data;
    }
}
