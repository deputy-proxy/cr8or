<?php

namespace App\Filament\Resources\AgentPermissions\Pages;

use App\Filament\Resources\AgentPermissions\AgentPermissionResource;
use App\Models\AgentPermission;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;

class CreateAgentPermission extends CreateRecord
{
    protected static string $resource = AgentPermissionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $assignment = \App\Models\AgentAssignment::query()->findOrFail((int) $data['agent_assignment_id']);
        Gate::authorize('createForAgentAssignment', [AgentPermission::class, $assignment]);

        return $data;
    }
}
