<?php

namespace App\Filament\Resources\Memberships\Pages;

use App\Filament\Resources\Memberships\MembershipResource;
use App\Models\Membership;
use App\Models\Organization;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;

class CreateMembership extends CreateRecord
{
    protected static string $resource = MembershipResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $parent = Organization::query()->findOrFail((int) $data['organization_id']);
        Gate::authorize('createForOrganization', [Membership::class, $parent]);

        return $data;
    }
}