<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Models\Decision;
use App\Models\Enterprise;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

abstract class DecisionCapabilityTool extends GovernedCapabilityTool
{
    /**
     * @param  array<string, array<int, mixed>>  $rules
     */
    protected function executeDecision(
        Request $request,
        string $operation,
        array $rules,
        Closure $resolveAuthorization,
    ): Response|ResponseFactory {
        return $this->executeWithErrors($request, $operation, function () use ($request, $rules, $resolveAuthorization) {
            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            $input = $request->validate($rules);
            $enterprise = Enterprise::query()->findOrFail((int) $input['enterprise_id']);
            /** @var array{0: array{0: string, 1: mixed}, 1: array<string, mixed>} $authorization */
            $authorization = $resolveAuthorization($actor, $enterprise, $input);
            [$humanAbility, $targetContext] = $authorization;

            $result = $this->invokeCapability(
                app(CapabilityRegistry::class),
                $actor,
                $enterprise,
                $input,
                $targetContext,
                $humanAbility,
            );

            return Response::structured(['success' => true, 'result' => $result]);
        });
    }

    /** @param array<string, mixed> $input */
    protected function decision(Enterprise $enterprise, array $input): Decision
    {
        return Decision::query()
            ->where('enterprise_id', $enterprise->getKey())
            ->whereKey((int) $input['decision_id'])
            ->firstOrFail();
    }
}
