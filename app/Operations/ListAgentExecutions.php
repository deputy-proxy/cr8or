<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\AgentExecutionResourceService;

final class ListAgentExecutions implements Operation
{
    public function __construct(private readonly AgentExecutionResourceService $resources) {}

    public function execute(User $actor, array $input): mixed
    {
        $enterprise = $input['enterprise'] ?? Enterprise::query()->findOrFail((int) $input['enterprise_id']);
        $items = $this->resources->list($actor, $enterprise, isset($input['agent_assignment_id']) ? (int) $input['agent_assignment_id'] : null, $input['status'] ?? null, (int) ($input['limit'] ?? 50));

        return ['items' => $items->map(fn ($item) => $this->resources->get($actor, $enterprise, $item))->values()->all(), 'count' => $items->count()];
    }
}
