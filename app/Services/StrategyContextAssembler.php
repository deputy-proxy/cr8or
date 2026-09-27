<?php

namespace App\Services;

use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class StrategyContextAssembler
{
    private const CONTEXT_LIMIT = 100;

    /**
     * @return array<string, mixed>
     */
    public function assemble(User $user, Enterprise $enterprise): array
    {
        Gate::forUser($user)->authorize('view', $enterprise);

        $goals = $enterprise->goals()
            ->orderBy('id')
            ->limit(self::CONTEXT_LIMIT)
            ->get();

        $kpis = $enterprise->kpis()
            ->orderBy('id')
            ->limit(self::CONTEXT_LIMIT)
            ->get();

        $goalById = $goals->keyBy('id');
        $kpiById = $kpis->keyBy('id');

        $objectives = $enterprise->objectives()
            ->orderBy('id')
            ->limit(self::CONTEXT_LIMIT)
            ->get()
            ->map(function ($objective) use ($goalById, $kpiById) {
                $goal = $goalById->get($objective->goal_id);
                $kpi = $kpiById->get($objective->kpi_id);

                return [
                    'id' => $objective->getKey(),
                    'name' => $objective->name,
                    'description' => $objective->description,
                    'goal' => $goal === null ? null : [
                        'id' => $goal->getKey(),
                        'name' => $goal->name,
                        'description' => $goal->description,
                        'status' => $goal->status,
                    ],
                    'kpi' => $kpi === null ? null : [
                        'id' => $kpi->getKey(),
                        'name' => $kpi->name,
                        'definition' => $kpi->definition,
                        'unit' => $kpi->unit,
                        'target_value' => $kpi->target_value,
                        'current_value' => $kpi->current_value,
                        'status' => $kpi->status,
                    ],
                    'strategies' => $objective->strategies()
                        ->orderBy('id')
                        ->limit(self::CONTEXT_LIMIT)
                        ->get()
                        ->map(fn ($strategy) => [
                            'id' => $strategy->getKey(),
                            'name' => $strategy->name,
                            'description' => $strategy->description,
                            'plans' => $strategy->plans()
                                ->orderBy('id')
                                ->limit(self::CONTEXT_LIMIT)
                                ->get()
                                ->map(fn ($plan) => [
                                    'id' => $plan->getKey(),
                                    'name' => $plan->name,
                                    'description' => $plan->description,
                                    'initiatives' => $plan->initiatives()
                                        ->orderBy('id')
                                        ->limit(self::CONTEXT_LIMIT)
                                        ->get()
                                        ->map(fn ($initiative) => [
                                            'id' => $initiative->getKey(),
                                            'name' => $initiative->name,
                                            'description' => $initiative->description,
                                        ])
                                        ->all(),
                                ])
                                ->all(),
                        ])
                        ->all(),
                ];
            })
            ->all();

        return [
            'enterprise' => [
                'id' => $enterprise->getKey(),
                'name' => $enterprise->name,
                'slug' => $enterprise->slug,
                'status' => $enterprise->status,
            ],
            'goals' => $goals->map(fn ($goal) => [
                'id' => $goal->getKey(),
                'name' => $goal->name,
                'description' => $goal->description,
                'status' => $goal->status,
            ])->all(),
            'kpis' => $kpis->map(fn ($kpi) => [
                'id' => $kpi->getKey(),
                'name' => $kpi->name,
                'definition' => $kpi->definition,
                'unit' => $kpi->unit,
                'target_value' => $kpi->target_value,
                'current_value' => $kpi->current_value,
                'status' => $kpi->status,
            ])->all(),
            'objectives' => $objectives,
        ];
    }
}