<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\AgentAssignment;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\AgentAssignmentService;

final class GetAgentAssignment implements Operation
{
    public function __construct(private readonly AgentAssignmentService $assignments) {}

    public function execute(User $actor, array $input): mixed
    {
        $enterprise = $input['enterprise'] ?? Enterprise::query()->findOrFail((int) $input['enterprise_id']);
        $assignment = $input['assignment'] ?? AgentAssignment::query()->findOrFail((int) $input['agent_assignment_id']);

        return $this->assignments->serialize($this->assignments->get($actor, $enterprise, $assignment));
    }
}
