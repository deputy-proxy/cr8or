<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\User;
use App\Models\Workflow;
use App\Services\WorkflowEntryPointService;

final class DuplicateWorkflow implements Operation
{
    public function __construct(private readonly WorkflowEntryPointService $workflows) {}

    public function execute(User $actor, array $input): Workflow
    {
        $workflow = $input['workflow'] ?? Workflow::query()->findOrFail((int) $input['workflow_id']);

        return $this->workflows->duplicate($actor, $workflow);
    }
}