<?php

namespace App\Mcp\Tools;

use App\Models\Execution;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-executions')]
#[Description('Discover authorized execution resources in CR8OR.')]
class ListExecutionTool extends DiscoveryListTool
{
    protected static function modelClass(): string
    {
        return Execution::class;
    }

    protected static function filters(): array
    {
        return [
            'project_id' => 'Project id.',
            'task_id' => 'Task id.',
            'work_item_id' => 'Work item id.',
        ];
    }

    protected static function fields(): array
    {
        return [
            'id' => 'id',
            'organization_id' => 'organization_id',
            'enterprise_id' => 'enterprise_id',
            'project_id' => 'project_id',
            'task_id' => 'task_id',
            'work_item_id' => 'work_item_id',
            'status' => 'status',
            'started_at' => 'started_at',
            'completed_at' => 'completed_at',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ];
    }

    protected static function searchableColumns(): array
    {
        return [];
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

