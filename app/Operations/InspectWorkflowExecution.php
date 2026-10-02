<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\User;
use App\Models\WorkflowExecution;
use App\Services\WorkflowEntryPointService;

final class InspectWorkflowExecution implements Operation
{
    public function __construct(private readonly WorkflowEntryPointService $workflows) {}

    public function execute(User $actor, array $input): mixed
    {
        $execution = $input['execution'] ?? WorkflowExecution::query()->findOrFail((int) $input['workflow_execution_id']);

        return $this->workflows->inspect($actor, $execution);
    }
}