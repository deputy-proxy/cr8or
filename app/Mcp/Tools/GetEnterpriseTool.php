<?php

namespace App\Mcp\Tools;

use App\Models\Enterprise;
use App\Models\User;
use App\Services\EnterpriseIdentityResolver;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get-enterprise')]
#[Description('Get an authorized enterprise from CR8OR by canonical slug or internal id. Slug is preferred for named-enterprise workflows.')]
class GetEnterpriseTool extends DiscoveryGetTool
{
    protected static function modelClass(): string
    {
        return Enterprise::class;
    }

    protected static function fields(): array
    {
        return [
            'id' => 'id',
            'organization_id' => 'organization_id',
            'name' => 'name',
            'slug' => 'slug',
            'status' => 'status',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->min(1)->description('Internal enterprise id. Use slug for named-enterprise workflows.'),
            'slug' => $schema->string()->min(1)->max(255)->description('Canonical enterprise slug or human-entered enterprise name/slug.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $resolver = app(EnterpriseIdentityResolver::class);

        return $this->executeWithErrors($request, 'mcp.discovery.get', function () use ($request, $resolver) {
            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            $validated = $request->validate([
                'id' => ['nullable', 'integer', 'min:1'],
                'slug' => ['nullable', 'string', 'min:1', 'max:255'],
            ]);

            if (($validated['id'] ?? null) === null && ($validated['slug'] ?? null) === null) {
                throw new \InvalidArgumentException('Enterprise identity requires an id or slug.');
            }

            $enterprise = $resolver->resolve(
                $actor,
                isset($validated['id']) ? (int) $validated['id'] : null,
                $validated['slug'] ?? null,
            );

            Gate::forUser($actor)->authorize('view', $enterprise);

            return Response::structured([
                'success' => true,
                'result' => static::serializeModel($enterprise),
            ]);
        });
    }

    /**
     * @param  Builder<Model>  $query
     */
    // @phpstan-ignore missingType.generics
    protected static function scopeQuery(Builder $query, User $actor): Builder
    {
        $organizationIds = $actor->memberships()->pluck('organization_id');

        return $query->whereIn('organization_id', $organizationIds);
    }
}