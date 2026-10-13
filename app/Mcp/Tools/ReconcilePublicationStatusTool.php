<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('reconcile-publication-status')]
#[Description('Reconcile a publication against the destination network and record the authoritative result.')]
final class ReconcilePublicationStatusTool extends GovernedCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'publication_id' => $schema->integer()->min(1)->required(),
            'agent_assignment_id' => $schema->integer()->min(1),
            'agent_execution_id' => $schema->integer()->min(1),
            'approval_request_id' => $schema->integer()->min(1),
        ];
    }

    public function handle(Request $request, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'publication.status.reconcile', function () use ($request, $registry) {
            $validated = $request->validate([
                'publication_id' => ['required', 'integer', 'min:1', 'exists:publications,id'],
                'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'],
                'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
                'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id'],
            ]);

            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            $publication = Publication::query()->with('enterprise')->findOrFail((int) $validated['publication_id']);
            $input = [
                'publication' => $publication,
                'enterprise' => $publication->enterprise,
                'target_context' => ['enterprise_id' => (int) $publication->enterprise_id, 'publication_id' => (int) $publication->id],
                ...$validated,
            ];
            if (isset($validated['agent_assignment_id'], $validated['agent_execution_id'])) {
                $input['expert_slug'] = 'marketing';
            }

            return Response::structured([
                'success' => true,
                'result' => $this->executeCapability($registry, $actor, $input),
            ]);
        });
    }

    /** @param array<string, mixed> $input
     * @return array{0: string, 1: mixed}|null
     */
    protected function humanAbility(User $actor, array $input): ?array
    {
        $publication = $input['publication'] ?? null;

        return $publication instanceof Publication ? ['reconcileStatus', $publication] : null;
    }
}
