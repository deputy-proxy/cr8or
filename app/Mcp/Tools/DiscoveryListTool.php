<?php

namespace App\Mcp\Tools;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

abstract class DiscoveryListTool extends AuthorizedTool
{
    /** @return class-string<Model> */
    abstract protected static function modelClass(): string;

    /** @return array<string, string> */
    protected static function filters(): array
    {
        return [];
    }

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

    protected static function authorizeList(User $actor): void
    {
        // The default list boundary is enforced by the organization/enterprise scope query.
    }

    public function schema(JsonSchema $schema): array
    {
        $properties = [
            'per_page' => $schema->integer()->min(1)->max(50)->description('Results per page.')->default(20),
            'page' => $schema->integer()->min(1)->description('1-based page number.')->default(1),
        ];

        if (static::hasField('enterprise_id')) {
            $properties['enterprise_id'] = $schema->integer()->min(1)->description('Optional enterprise scope.');
        }
        if (static::hasField('status')) {
            $properties['status'] = $schema->string()->min(1)->max(100)->description('Optional status/state filter.');
        }
        if (static::searchableColumns() !== []) {
            $properties['search'] = $schema->string()->min(1)->max(255)->description('Optional name/title/slug search.');
        }

        foreach (static::filters() as $name => $description) {
            $properties[$name] = $schema->integer()->min(1)->description($description);
        }

        return $properties;
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.discovery.list', function () use ($request) {
            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            $rules = [
                'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
                'page' => ['nullable', 'integer', 'min:1'],
            ];
            if (static::hasField('enterprise_id')) {
                $rules['enterprise_id'] = ['nullable', 'integer', 'min:1'];
            }
            if (static::hasField('status')) {
                $rules['status'] = ['nullable', 'string', 'min:1', 'max:100'];
            }
            if (static::searchableColumns() !== []) {
                $rules['search'] = ['nullable', 'string', 'min:1', 'max:255'];
            }
            foreach (array_keys(static::filters()) as $filter) {
                $rules[$filter] = ['nullable', 'integer', 'min:1'];
            }

            $validated = $request->validate($rules);
            static::authorizeList($actor);

            $query = static::scopeQuery(static::modelClass()::query(), $actor);

            if (isset($validated['enterprise_id'])) {
                static::applyEnterpriseFilter($query, (int) $validated['enterprise_id']);
            }
            if (isset($validated['status'])) {
                $query->where('status', $validated['status']);
            }
            if (isset($validated['search'])) {
                static::applySearch($query, $validated['search']);
            }
            foreach (array_keys(static::filters()) as $filter) {
                if (isset($validated[$filter])) {
                    $query->where($filter, $validated[$filter]);
                }
            }

            $paginator = $query->orderByDesc('id')->paginate(
                (int) ($validated['per_page'] ?? 20),
                ['*'],
                'page',
                (int) ($validated['page'] ?? 1),
            );

            return Response::structured([
                'success' => true,
                'result' => [
                    'items' => array_map(
                        fn (Model $model): array => static::serializeModel($model),
                        $paginator->items(),
                    ),
                    'pagination' => [
                        'page' => $paginator->currentPage(),
                        'per_page' => $paginator->perPage(),
                        'total' => $paginator->total(),
                        'last_page' => $paginator->lastPage(),
                    ],
                ],
            ]);
        });
    }

    /**
     * @param  Builder<Model>  $query
     */
    protected static function applyEnterpriseFilter(Builder $query, int $enterpriseId): void
    {
        $query->where('enterprise_id', $enterpriseId);
    }

    protected static function hasField(string $field): bool
    {
        return in_array($field, array_values(static::fields()), true);
    }

    /**
     * @param  Builder<Model>  $query
     */
    protected static function applySearch(Builder $query, string $search): void
    {
        $query->where(function (Builder $query) use ($search): void {
            $first = true;
            foreach (['name', 'title', 'slug'] as $field) {
                if (! in_array($field, static::searchableColumns(), true)) {
                    continue;
                }
                $method = $first ? 'where' : 'orWhere';
                $query->{$method}($field, 'like', '%'.$search.'%');
                $first = false;
            }
        });
    }

    /** @return list<string> */
    protected static function searchableColumns(): array
    {
        return ['name', 'title', 'slug'];
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
