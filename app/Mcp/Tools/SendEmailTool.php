<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('send-email')]
#[Description('Send a plain-text email through CR8OR’s configured mail transport to the authenticated actor’s verified email address. No arbitrary recipient is accepted.')]
final class SendEmailTool extends GovernedCapabilityTool
{
    protected function expertSlug(): string
    {
        return 'communication';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required()->description('Enterprise context used for server-side authorization.'),
            'subject' => $schema->string()->min(1)->max(255)->required(),
            'text_body' => $schema->string()->min(1)->max(100000)->required(),
            'agent_assignment_id' => $schema->integer()->min(1),
            'agent_execution_id' => $schema->integer()->min(1),
        ];
    }

    public function handle(Request $request, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.communication.email.send', function () use ($request, $registry) {
            $validated = $request->validate([
                'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
                'subject' => ['required', 'string', 'min:1', 'max:255', 'not_regex:/[\r\n]/'],
                'text_body' => ['required', 'string', 'min:1', 'max:100000'],
                'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'],
                'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
            ]);

            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            return Response::structured([
                'success' => true,
                'result' => $this->executeCapability($registry, $actor, $validated),
            ]);
        });
    }
}
