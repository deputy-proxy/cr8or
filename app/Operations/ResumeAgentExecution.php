<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Enums\AgentExecutionMode;
use App\Models\AgentExecution;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\AgentExecutionResourceService;
use App\Services\AgentExecutionService;

final class ResumeAgentExecution implements Operation
{
    public function __construct(private readonly AgentExecutionService $executions, private readonly AgentExecutionResourceService $resources) {}

    public function execute(User $actor, array $input): mixed
    {
        $execution = $input['execution'] ?? AgentExecution::query()->findOrFail((int) $input['agent_execution_id']);
        if ($execution->mode === AgentExecutionMode::INTERACTIVE && isset($input['capability_requests'])) {
            $execution->execution_context = array_merge($execution->execution_context ?? [], [
                'interactive_capability_requests' => array_values($input['capability_requests']),
            ]);
            $execution->save();
        }

        $this->executions->queueResume($execution, $actor);
        $enterprise = $input['enterprise'] ?? Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        return $this->resources->get($actor, $enterprise, $execution->refresh());
    }
}