<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\MemoryResourceService;

final class ListMemory implements Operation
{
    public function __construct(private readonly MemoryResourceService $memory) {}

    public function execute(User $actor, array $input): mixed
    {
        $enterprise = $input['enterprise'] ?? Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        return $this->memory->list(
            $actor,
            $enterprise,
            $input['type'] ?? null,
            isset($input['agent_descriptor_id']) ? (int) $input['agent_descriptor_id'] : null,
            $input['search'] ?? null,
            $input['status'] ?? null,
            (int) ($input['page'] ?? 1),
            (int) ($input['per_page'] ?? 25),
        );
    }
}