<?php

namespace App\Filament\Resources\AgentRuntimePolicies\Pages;

use App\Filament\Resources\AgentRuntimePolicies\AgentRuntimePolicyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAgentRuntimePolicies extends ListRecords
{
    protected static string $resource = AgentRuntimePolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}