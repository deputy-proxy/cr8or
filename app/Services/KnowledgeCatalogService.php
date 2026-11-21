<?php

namespace App\Services;

use App\Models\Enterprise;
use App\Models\KnowledgeContext;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class KnowledgeCatalogService
{
    private const CONFIG = [
        'source' => [KnowledgeSource::class, KnowledgeSource::class, ['name', 'type', 'description', 'uri', 'metadata'], ['name', 'description']],
        'document' => [KnowledgeDocument::class, KnowledgeDocument::class, ['knowledge_source_id', 'title', 'identifier', 'status', 'content', 'metadata'], ['title', 'identifier', 'content']],
        'context' => [KnowledgeContext::class, KnowledgeContext::class, ['name', 'type', 'description', 'data'], ['name', 'description']],
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(User $actor, Enterprise $enterprise, string $resource, array $input): array
    {
        [$model, $policy] = $this->config($resource);
        Gate::forUser($actor)->authorize('createForEnterprise', [$policy, $enterprise]);
        $attributes = $this->attributes($resource, $input);
        $this->assertRelationships($enterprise, $resource, $attributes);
        $record = $model::query()->create(['enterprise_id' => $enterprise->getKey(), ...$attributes]);

        return $this->serialize($record->refresh(), $resource);
    }

    /** @return array<string, mixed> */
    public function get(User $actor, Enterprise $enterprise, string $resource, int $id): array
    {
        [$model] = $this->config($resource);
        $record = $model::query()->where('enterprise_id', $enterprise->getKey())->findOrFail($id);
        Gate::forUser($actor)->authorize('view', $record);

        return $this->serialize($record, $resource);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public function list(User $actor, Enterprise $enterprise, string $resource, array $filters): array
    {
        [$model] = $this->config($resource);
        Gate::forUser($actor)->authorize('view', $enterprise);
        $query = $model::query()->withCount(match ($resource) {
            'source' => ['documents', 'items'],
            'document' => ['items', 'references'],
            'context' => ['items'],
            default => throw new \LogicException('Unsupported Knowledge resource.'),
        })->where('enterprise_id', $enterprise->getKey());
        if ($resource === 'document') {
            $query->with('source:id,name,type');
        }
        $this->applyFilters($query, $resource, $filters);
        $page = $query->orderByDesc('updated_at')->orderByDesc('id')->paginate(
            min(50, max(1, (int) ($filters['per_page'] ?? 25))), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)),
        );

        return [
            'items' => array_values($page->getCollection()->map(fn ($record): array => $this->serialize($record, $resource, false))->all()),
            'pagination' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(User $actor, Enterprise $enterprise, string $resource, int $id, array $input): array
    {
        [$model] = $this->config($resource);
        $record = $model::query()->where('enterprise_id', $enterprise->getKey())->findOrFail($id);
        Gate::forUser($actor)->authorize('update', $record);
        $attributes = $this->attributes($resource, $input);
        if ($attributes === []) {
            throw ValidationException::withMessages(['record_id' => 'At least one mutable field must be supplied.']);
        }
        $this->assertRelationships($enterprise, $resource, $attributes);
        $record->fill($attributes)->save();

        return $this->serialize($record->refresh(), $resource);
    }

    /** @return array{class-string<Model>, class-string<Model>, list<string>, list<string>} */
    private function config(string $resource): array
    {
        return self::CONFIG[$resource] ?? throw ValidationException::withMessages(['resource' => 'Unsupported Knowledge resource.']);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function attributes(string $resource, array $input): array
    {
        $allowed = $this->config($resource)[2];

        return array_intersect_key($input, array_flip($allowed));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertRelationships(Enterprise $enterprise, string $resource, array $attributes): void
    {
        if ($resource === 'document' && isset($attributes['knowledge_source_id'])) {
            $source = KnowledgeSource::query()->where('enterprise_id', $enterprise->getKey())->find($attributes['knowledge_source_id']);
            if ($source === null) {
                throw ValidationException::withMessages(['knowledge_source_id' => 'The selected Knowledge Source does not belong to the requested Enterprise.']);
            }
        }
    }

    /**
     * @param  Builder<Model>        $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, string $resource, array $filters): void
    {
        if (isset($filters['search']) && trim((string) $filters['search']) !== '') {
            $term = '%'.mb_strtolower(trim((string) $filters['search'])).'%';
            $query->where(function (Builder $nested) use ($resource, $term): void {
                $columns = match ($resource) {
                    'source' => ['name', 'description'],
                    'document' => ['title', 'identifier', 'content'],
                    'context' => ['name', 'description'],
                    default => throw new \LogicException('Unsupported Knowledge resource.'),
                };

                foreach ($columns as $index => $column) {
                    $sql = match ($column) {
                        'name' => 'LOWER(name) LIKE ?',
                        'description' => 'LOWER(description) LIKE ?',
                        'title' => 'LOWER(title) LIKE ?',
                        'identifier' => 'LOWER(identifier) LIKE ?',
                        'content' => 'LOWER(content) LIKE ?',
                        default => throw new \LogicException('Unsupported Knowledge search column.'),
                    };
                    if ($index === 0) {
                        $nested->whereRaw($sql, [$term]);
                    } else {
                        $nested->orWhereRaw($sql, [$term]);
                    }
                }
            });
        }

        $filterColumn = match ($resource) {
            'source', 'context' => 'type',
            'document' => 'status',
            default => throw new \LogicException('Unsupported Knowledge resource.'),
        };
        if (isset($filters[$filterColumn]) && $filters[$filterColumn] !== '') {
            $query->where($filterColumn, $filters[$filterColumn]);
        }
        if ($resource === 'document' && isset($filters['knowledge_source_id'])) {
            $query->where('knowledge_source_id', $filters['knowledge_source_id']);
        }
    }

    /** @return array<string, mixed> */
    private function serialize(Model $record, string $resource, bool $includeDetails = true): array
    {
        $countAttributes = match ($resource) {
            'source' => ['documents_count', 'items_count'],
            'document' => ['items_count', 'references_count'],
            'context' => ['items_count'],
            default => throw new \LogicException('Unsupported Knowledge resource.'),
        };
        if (array_any($countAttributes, fn (string $column): bool => array_key_exists($column, $record->getAttributes())) === false) {
            $relations = match ($resource) {
                'source' => ['documents', 'items'],
                'document' => ['items', 'references'],
                'context' => ['items'],
                default => throw new \LogicException('Unsupported Knowledge resource.'),
            };
            $record->loadCount($relations);
        }

        $data = $record->toArray();
        if ($includeDetails === false) {
            unset($data['content'], $data['metadata'], $data['data']);
        }
        $data['id'] = $record->getKey();
        $data['enterprise_id'] = $record->getAttribute('enterprise_id');
        if ($resource === 'document' && $record instanceof KnowledgeDocument) {
            $record->loadMissing('source:id,name,type');
            $data['source'] = $record->source ? ['id' => $record->source->getKey(), 'name' => $record->source->name, 'type' => $record->source->type] : null;
        }

        return $data;
    }
}
