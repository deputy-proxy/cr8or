<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Objective;
use App\Models\Strategy;
use App\Models\User;
use App\Services\StrategyService;

final class CreateStrategy implements Operation
{
    public function __construct(private readonly StrategyService $strategies) {}

    public function execute(User $actor, array $input): Strategy
    {
        $objective = $input['objective'] instanceof Objective
            ? $input['objective']
            : Objective::query()->findOrFail((int) $input['objective_id']);

        return $this->strategies->create($actor, $objective, $input);
    }
}
