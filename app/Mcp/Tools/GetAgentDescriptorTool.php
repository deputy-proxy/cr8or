<?php

namespace App\Mcp\Tools;

use App\Models\AgentDescriptor;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get-agent')]
#[Description('Get an authorized agent by id from CR8OR.')]
class GetAgentDescriptorTool extends DiscoveryGetTool
{
    protected static function modelClass(): string
    {
        return AgentDescriptor::class;
    }

    protected static function fields(): array
    {
        return [
            'id' => 'id',
            'slug' => 'slug',
            'enabled' => 'enabled',
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

        return $query;
    }
}
