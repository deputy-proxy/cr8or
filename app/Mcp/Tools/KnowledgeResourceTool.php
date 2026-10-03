<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

abstract class KnowledgeResourceTool extends AuthorizedTool implements \App\Contracts\CapabilityBoundaryTool
{
    /** @return class-string<Operation> */
    abstract protected function operationClass(): string;

    /** @param array<string, mixed> $input */
    protected function executeOperation(Request $request, array $input): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.knowledge.resource', function () use ($request, $input) {
            $actor = $request->user();

            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            $result = $this->executeGovernedWhenMapped($actor, $input);

            return Response::structured(['success' => true, 'result' => $result]);
        });
    }

    /** @param array<string, mixed> $input */
    private function executeGovernedWhenMapped(User $actor, array $input): mixed
    {
        $registry = app(CapabilityRegistry::class);

        try {
            $registry->forTool(static::class);
        } catch (\InvalidArgumentException) {
            return app($this->operationClass())->execute($actor, $input);
        }

        $enterprise = ($input['enterprise'] ?? null) instanceof Enterprise
            ? $input['enterprise']
            : Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        return $this->invokeCapability(
            $registry,
            $actor,
            $enterprise,
            $input,
            ['enterprise_id' => $enterprise->getKey()],
        );
    }
}