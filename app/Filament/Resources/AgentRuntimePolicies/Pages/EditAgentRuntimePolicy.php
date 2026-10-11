<?php

namespace App\Filament\Resources\AgentRuntimePolicies\Pages;

use App\Filament\Resources\AgentRuntimePolicies\AgentRuntimePolicyResource;
use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use Filament\Resources\Pages\EditRecord;

class EditAgentRuntimePolicy extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = AgentRuntimePolicyResource::class;
}
