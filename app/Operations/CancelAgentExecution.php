<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\AgentExecution;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\AgentExecutionResourceService;
use App\Services\AgentExecutionService;

final class CancelAgentExecution implements Operation
{
    public function __construct(private readonly AgentExecutionService $executions, private readonly AgentExecutionResourceService $resources) {}

    public function execute(User $actor, array $input): mixed
    {
        $execution = $input['execution'] ?? AgentExecution::query()->findOrFail((int) $input['agent_execution_id']);
        $execution = $this->executions->cancel($execution, $actor, $input['reason'] ?? null);
        $enterprise = $input['enterprise'] ?? Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        return $this->resources->get($actor, $enterprise, $execution);
    }
}
