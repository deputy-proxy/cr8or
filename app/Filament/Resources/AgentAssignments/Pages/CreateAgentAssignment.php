<?php

namespace App\Filament\Resources\AgentAssignments\Pages;

use App\Filament\Resources\AgentAssignments\AgentAssignmentResource;
use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use Filament\Resources\Pages\CreateRecord;

class CreateAgentAssignment extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected static string $resource = AgentAssignmentResource::class;
}
