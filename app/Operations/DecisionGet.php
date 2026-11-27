<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Decision;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\DomainResourceService;

final class DecisionGet implements Operation
{
    public function __construct(private readonly DomainResourceService $decisions) {}

    public function execute(User $actor, array $input): mixed
    {
        $enterprise = $input['enterprise'] ?? Enterprise::query()->findOrFail((int) $input['enterprise_id']);
        $decision = Decision::query()
            ->where('enterprise_id', $enterprise->getKey())
            ->whereKey((int) $input['decision_id'])
            ->firstOrFail();

        return $this->decisions->getDecision($actor, $enterprise, $decision);
    }
}
