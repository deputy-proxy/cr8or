<?php

namespace App\Filament\Resources\AgentAssignments\Pages;

use App\Filament\Resources\AgentAssignments\AgentAssignmentResource;
use App\Models\AgentAssignment;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;

class CreateAgentAssignment extends CreateRecord
{
    protected static string $resource = AgentAssignmentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $record = new AgentAssignment($data);
        Gate::authorize('createForAgentAssignment', [AgentAssignment::class, $record]);

        return $data;
    }
}