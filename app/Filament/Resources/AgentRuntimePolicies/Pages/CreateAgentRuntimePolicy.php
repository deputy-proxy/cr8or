<?php

namespace App\Filament\Resources\AgentRuntimePolicies\Pages;

use App\Filament\Resources\AgentRuntimePolicies\AgentRuntimePolicyResource;
use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use Filament\Resources\Pages\CreateRecord;

class CreateAgentRuntimePolicy extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected static string $resource = AgentRuntimePolicyResource::class;
}
