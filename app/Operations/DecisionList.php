<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\DomainResourceService;

final class DecisionList implements Operation
{
    public function __construct(private readonly DomainResourceService $decisions) {}

    public function execute(User $actor, array $input): mixed
    {
        $enterprise = $input['enterprise'] ?? Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        return $this->decisions->listDecisions($actor, $enterprise, $input);
    }
}
