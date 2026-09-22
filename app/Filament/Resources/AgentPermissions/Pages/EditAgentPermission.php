<?php

namespace App\Filament\Resources\AgentPermissions\Pages;

use App\Filament\Resources\AgentPermissions\AgentPermissionResource;
use App\Models\AgentPermission;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Gate;

class EditAgentPermission extends EditRecord
{
    protected static string $resource = AgentPermissionResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var AgentPermission $record */ $record = $this->record;
        Gate::authorize('update', [$record, $record->agentAssignment]);
        $assignment = \App\Models\AgentAssignment::query()->findOrFail((int) $data['agent_assignment_id']);
        Gate::authorize('update', [$record, $assignment]);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\DeleteAction::make()];
    }
}
