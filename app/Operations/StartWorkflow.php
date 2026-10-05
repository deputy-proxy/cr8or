<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\User;
use App\Models\Workflow;
use App\Services\WorkflowEntryPointService;

final class StartWorkflow implements Operation
{
    public function __construct(private readonly WorkflowEntryPointService $workflows) {}

    public function execute(User $actor, array $input): mixed
    {
        $workflow = $input['workflow'] ?? Workflow::query()->findOrFail((int) $input['workflow_id']);

        $enterprise = $input['enterprise'] ?? Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        return $this->workflows->start($actor, $workflow, $input['input'] ?? [], (string) $input['idempotency_key'], $input['correlation_id'] ?? null, false, $enterprise);
    }
}