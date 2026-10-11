<?php

namespace App\Filament\Resources\AgentAssignments\Pages;

use App\Filament\Resources\AgentAssignments\AgentAssignmentResource;
use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use Filament\Resources\Pages\EditRecord;

class EditAgentAssignment extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = AgentAssignmentResource::class;
}
