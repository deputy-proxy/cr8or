<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Strategy;
use App\Models\User;
use App\Services\StrategyService;

final class UpdateStrategy implements Operation
{
    public function __construct(private readonly StrategyService $strategies) {}

    public function execute(User $actor, array $input): Strategy
    {
        $strategy = $input['strategy'] instanceof Strategy
            ? $input['strategy']
            : Strategy::query()->findOrFail((int) $input['strategy_id']);

        return $this->strategies->update(
            $actor,
            $strategy,
            $input['attributes'] ?? array_intersect_key($input, array_flip(['name', 'description'])),
        );
    }
}
