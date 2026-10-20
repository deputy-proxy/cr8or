<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\DefinesPersonaSchema;
use App\Models\Persona;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get-persona')]
#[Description('Get an authorized persona by id with the complete profile and associated product IDs.')]
class GetPersonaTool extends DiscoveryGetTool
{
    use DefinesPersonaSchema;

    protected static function modelClass(): string
    {
        return Persona::class;
    }

    protected static function fields(): array
    {
        return [
            'id' => 'id',
            'enterprise_id' => 'enterprise_id',
            'name' => 'name',
            'description' => 'description',
            'status' => 'status',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ];
    }

    /**
     * @param  Builder<Model>  $query
     */
    // @phpstan-ignore missingType.generics
    protected static function scopeQuery(Builder $query, User $actor): Builder
    {
        $organizationIds = $actor->memberships()->pluck('organization_id');

        return $query->with('products')
            ->whereHas('enterprise', fn (Builder $q) => $q->whereIn('organization_id', $organizationIds));
    }

    /** @return array<string, mixed> */
    protected static function serializeModel(Model $model): array
    {
        if (! $model instanceof Persona) {
            throw new \LogicException('Unexpected persona result.');
        }

        return array_merge([
            'id' => $model->id,
            'enterprise_id' => $model->enterprise_id,
            'name' => $model->name,
            'description' => $model->description,
            'status' => $model->status,
            'created_at' => $model->created_at?->toISOString(),
            'updated_at' => $model->updated_at?->toISOString(),
        ], static::personaResult($model));
    }
}