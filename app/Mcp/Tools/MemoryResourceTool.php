<?php

namespace App\Mcp\Tools;

use App\Contracts\Operation;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

abstract class MemoryResourceTool extends AuthorizedTool
{
    /** @return class-string<Operation> */
    abstract protected function operationClass(): string;

    /** @param array<string, mixed> $input */
    protected function executeOperation(Request $request, array $input): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.memory.resource', function () use ($request, $input) {
            $actor = $request->user();

            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            return Response::structured([
                'success' => true,
                'result' => app($this->operationClass())->execute($actor, $input),
            ]);
        });
    }
}