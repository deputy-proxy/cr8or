<?php

namespace App\Services;

use App\Models\Objective;
use App\Models\Strategy;
use App\Models\User;

class StrategyService
{
    /** @param array<string, mixed> $attributes */
    public function create(User $actor, Objective $objective, array $attributes): Strategy
    {
        return $objective->strategies()->create([
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, Strategy $strategy, array $attributes): Strategy
    {
        $strategy->update($attributes);

        return $strategy->refresh();
    }
}
