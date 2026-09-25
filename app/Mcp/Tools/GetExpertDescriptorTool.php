<?php

namespace App\Mcp\Tools;

use App\Models\ExpertDescriptor;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get-expert')]
#[Description('Get an authorized expert by id from CR8OR.')]
class GetExpertDescriptorTool extends DiscoveryGetTool
{
    protected static function modelClass(): string
    {
        return ExpertDescriptor::class;
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
