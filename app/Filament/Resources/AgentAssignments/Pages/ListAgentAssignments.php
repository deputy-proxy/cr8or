<?php

namespace App\Filament\Resources\AgentAssignments\Pages;

use App\Filament\Resources\AgentAssignments\AgentAssignmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAgentAssignments extends ListRecords
{
    protected static string $resource = AgentAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}