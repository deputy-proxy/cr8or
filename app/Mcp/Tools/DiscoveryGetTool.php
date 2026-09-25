<?php

namespace App\Mcp\Tools;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

abstract class DiscoveryGetTool extends AuthorizedTool
{
    /** @return class-string<Model> */
    abstract protected static function modelClass(): string;

    /** @return array<string, string> */
    protected static function fields(): array
    {
        return ['id' => 'id', 'created_at' => 'created_at', 'updated_at' => 'updated_at'];
    }

    /**
     * @param  Builder<Model>  $query
     */
    // @phpstan-ignore missingType.generics
    protected static function scopeQuery(Builder $query, User $actor): Builder
    {
        return $query->whereIn('organization_id', $actor->memberships()->pluck('organization_id'));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->min(1)->description('Resource id.')->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.discovery.get', function () use ($request) {
            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            $validated = $request->validate(['id' => ['required', 'integer', 'min:1']]);
            $model = static::scopeQuery(static::modelClass()::query(), $actor)
                ->whereKey($validated['id'])
                ->firstOrFail();

            Gate::forUser($actor)->authorize('view', $model);

            return Response::structured([
                'success' => true,
                'result' => static::serializeModel($model),
            ]);
        });
    }

    /** @return array<string, mixed> */
    protected static function serializeModel(Model $model): array
    {
        $result = [];
        foreach (static::fields() as $output => $attribute) {
            $result[$output] = $model->getAttribute($attribute);
        }

        return $result;
    }
}

