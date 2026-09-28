<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\AgentExecution;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\AgentExecutionResourceService;

final class GetAgentExecution implements Operation
{
    public function __construct(private readonly AgentExecutionResourceService $resources) {}

    public function execute(User $actor, array $input): mixed
    {
        $enterprise = $input['enterprise'] ?? Enterprise::query()->findOrFail((int) $input['enterprise_id']);
        $execution = $input['execution'] ?? AgentExecution::query()->findOrFail((int) $input['agent_execution_id']);

        return $this->resources->get($actor, $enterprise, $execution);
    }
}
