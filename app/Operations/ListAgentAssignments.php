<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\AgentAssignmentService;

final class ListAgentAssignments implements Operation
{
    public function __construct(private readonly AgentAssignmentService $assignments) {}

    public function execute(User $actor, array $input): mixed
    {
        $enterprise = $input['enterprise'] ?? Enterprise::query()->findOrFail((int) $input['enterprise_id']);
        $items = $this->assignments->list(
            $actor,
            $enterprise,
            isset($input['agent_descriptor_id']) ? (int) $input['agent_descriptor_id'] : null,
            $input['status'] ?? null,
            (int) ($input['limit'] ?? 50),
        );

        return [
            'items' => $items->map(fn ($item): array => $this->assignments->serialize($item))->values()->all(),
            'count' => $items->count(),
        ];
    }
}
