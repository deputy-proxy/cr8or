<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\WorkflowEntryPointService;

final class DiscoverWorkflows implements Operation
{
    public function __construct(private readonly WorkflowEntryPointService $workflows) {}

    public function execute(User $actor, array $input): mixed
    {
        $enterprise = $input['enterprise'] ?? Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        return $this->workflows->discover($actor, $enterprise, $input['canonical_key'] ?? null, $input['search'] ?? null, (int) ($input['per_page'] ?? 20), (int) ($input['page'] ?? 1));
    }
}