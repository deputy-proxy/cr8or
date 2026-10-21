<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Enums\ProductStatus;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-products')]
#[Description('List products in an authorized enterprise, returning product IDs suitable for same-enterprise persona associations. Supports status/search filters and pagination.')]
final class ListProductsTool extends GovernedCapabilityTool
{
    protected function expertSlug(): string
    {
        return 'product';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required()->description('Enterprise whose products should be listed. Access is checked server-side.'),
            'status' => $schema->string()->enum(array_column(ProductStatus::cases(), 'value'))->description('Optional lifecycle status filter.'),
            'search' => $schema->string()->min(1)->max(255)->description('Optional product name or slug search.'),
            'per_page' => $schema->integer()->min(1)->max(50)->default(20)->description('Results per page.'),
            'page' => $schema->integer()->min(1)->default(1)->description('1-based page number.'),
            'agent_assignment_id' => $schema->integer()->min(1),
            'agent_execution_id' => $schema->integer()->min(1),
            'approval_request_id' => $schema->integer()->min(1),
        ];
    }

    public function handle(Request $request, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.product.list', function () use ($request, $registry) {
            $validated = $request->validate([
                'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
                'status' => ['nullable', 'string', 'in:'.implode(',', array_column(ProductStatus::cases(), 'value'))],
                'search' => ['nullable', 'string', 'min:1', 'max:255'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
                'page' => ['nullable', 'integer', 'min:1'],
                'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'],
                'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
                'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id'],
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
