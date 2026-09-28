<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\AgentAssignmentService;

final class CreateAgentAssignment implements Operation
{
    public function __construct(private readonly AgentAssignmentService $assignments) {}

    public function execute(User $actor, array $input): mixed
    {
        $enterprise = $input['enterprise'] ?? Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        return $this->assignments->serialize($this->assignments->create($actor, $enterprise, $input));
    }
}
