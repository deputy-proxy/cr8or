<?php

namespace App\Mcp\Tools;

use App\Models\Initiative;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get-initiative')]
#[Description('Get an authorized initiative in the strategy hierarchy.')]
class GetInitiativeTool extends DiscoveryGetTool
{
    protected static function modelClass(): string
    {
        return Initiative::class;
    }

    protected static function fields(): array
    {
        return ['id' => 'id', 'plan_id' => 'plan_id', 'name' => 'name', 'description' => 'description', 'created_at' => 'created_at', 'updated_at' => 'updated_at'];
    }

    /** @param Builder<Model> $query */
    // @phpstan-ignore missingType.generics
    protected static function scopeQuery(Builder $query, User $actor): Builder
    {
        $organizationIds = $actor->memberships()->pluck('organization_id');

        return $query->whereHas('plan.strategy.objective.enterprise', fn (Builder $q) => $q->whereIn('organization_id', $organizationIds));
    }
}
