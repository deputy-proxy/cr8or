<?php

namespace App\Mcp\Tools;

use App\Models\Strategy;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get-strategy')]
#[Description('Get an authorized strategy by id from CR8OR.')]
class GetStrategyTool extends DiscoveryGetTool
{
    protected static function modelClass(): string
    {
        return Strategy::class;
    }

    protected static function fields(): array
    {
        return [
            'id' => 'id',
            'objective_id' => 'objective_id',
            'name' => 'name',
            'description' => 'description',
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

        return $query->whereHas('objective.enterprise', fn (Builder $q) => $q->whereIn('organization_id', $organizationIds));
    }
}
