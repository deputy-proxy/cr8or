<?php

namespace App\Mcp\Tools;

use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get-enterprise')]
#[Description('Get an authorized enterprise by id from CR8OR.')]
class GetEnterpriseTool extends DiscoveryGetTool
{
    protected static function modelClass(): string
    {
        return Enterprise::class;
    }

    protected static function fields(): array
    {
        return [
            'organization_id' => 'organization_id',
            'name' => 'name',
            'slug' => 'slug',
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

        return $query->whereIn('organization_id', $organizationIds);
    }
}
