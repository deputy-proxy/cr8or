<?php

namespace App\Mcp\Tools;

use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-approval-requests')]
#[Description('Discover authorized approval request resources in CR8OR.')]
class ListApprovalRequestTool extends DiscoveryListTool
{
    protected static function modelClass(): string
    {
        return ApprovalRequest::class;
    }

    protected static function filters(): array
    {
        return [
            'agent_assignment_id' => 'Agent assignment id.',
        ];
    }

    protected static function fields(): array
    {
        return [
            'id' => 'id',
            'organization_id' => 'organization_id',
            'enterprise_id' => 'enterprise_id',
            'agent_assignment_id' => 'agent_assignment_id',
            'agent_execution_id' => 'agent_execution_id',
            'capability' => 'capability',
            'status' => 'status',
            'requested_at' => 'requested_at',
            'expires_at' => 'expires_at',
            'decided_at' => 'decided_at',
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