<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Models\Competitor;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\McpCapabilityAuthorizer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-competitor')]
#[Description('Create an enterprise-scoped competitor record. Reusing the same exact name within the enterprise creates a new version and archives the current version; it does not overwrite history.')]
class CreateCompetitorTool extends GovernedCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->description('Target enterprise ID; access is verified server-side.')->required(),
            'name' => $schema->string()->min(1)->max(255)->description('Canonical competitor name.')->required(),
            'website' => $schema->string()->max(255)->description('Optional absolute website URL including scheme.'),
            'positioning' => $schema->string()->max(10000)->description('Optional positioning summary.'),
            'strengths' => $schema->array()->items($schema->string()->min(1)->max(500))->max(50)->description('Up to 50 known strengths; do not invent facts.'),
            'weaknesses' => $schema->array()->items($schema->string()->min(1)->max(500))->max(50)->description('Up to 50 known weaknesses; do not invent facts.'),
            'agent_assignment_id' => $schema->integer()->min(1),
            'agent_execution_id' => $schema->integer()->min(1),
            'approval_request_id' => $schema->integer()->min(1),
        ];
    }

    public function handle(Request $request, McpCapabilityAuthorizer $authorization, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.marketing.competitor.create', function () use ($request, $authorization, $registry) {
            $validated = $request->validate([
                'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
                'name' => ['required', 'string', 'min:1', 'max:255'],
                'website' => ['sometimes', 'nullable', 'url', 'max:255'],
                'positioning' => ['sometimes', 'nullable', 'string', 'max:10000'],
                'strengths' => ['sometimes', 'array', 'list', 'max:50'],
                'strengths.*' => ['required', 'string', 'min:1', 'max:500'],
                'weaknesses' => ['sometimes', 'array', 'list', 'max:50'],
                'weaknesses.*' => ['required', 'string', 'min:1', 'max:500'],
                'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'],
                'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
                'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id'],
            ]);

            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            $enterprise = Enterprise::query()->findOrFail((int) $validated['enterprise_id']);
            $authorization->authorizeMutation(
                $actor,
                $this->capability($registry),
                $enterprise,
                $validated['agent_assignment_id'] ?? null,
                $validated['agent_execution_id'] ?? null,
                $validated['approval_request_id'] ?? null,
                ['enterprise_id' => $enterprise->getKey()],
                ['createForEnterprise', [Competitor::class, $enterprise]],
            );

            /** @var Competitor $competitor */
            $competitor = $this->executeCapability($registry, $actor, ['enterprise' => $enterprise, ...$validated]);

            return Response::structured([
                'success' => true,
                'result' => [
                    'id' => $competitor->getKey(),
                    'enterprise_id' => $competitor->enterprise_id,
                    'version' => $competitor->version,
                    'name' => $competitor->name,
                    'website' => $competitor->website,
                    'positioning' => $competitor->positioning,
                    'strengths' => $competitor->strengths ?? [],
                    'weaknesses' => $competitor->weaknesses ?? [],
                    'status' => $competitor->status,
                    'effective_from' => self::timestamp($competitor->getRawOriginal('effective_from')),
                    'effective_to' => self::timestamp($competitor->getRawOriginal('effective_to')),
                    'supersedes_id' => $competitor->supersedes_id,
                    'is_current' => $competitor->is_current,
                ],
            ]);
        });
    }

    private static function timestamp(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse((string) $value)->toIso8601String();
    }
}