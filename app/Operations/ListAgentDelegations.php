<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\AgentDelegation;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

final class ListAgentDelegations implements Operation
{
    public function execute(User $actor, array $input): mixed
    {
        $enterprise = Enterprise::query()->findOrFail((int) $input['enterprise_id']);
        if (! Gate::forUser($actor)->allows('viewAny', AgentDelegation::class)) {
            $membership = $actor->memberships()->where('organization_id', $enterprise->organization_id)->exists();
            if (! $membership) {
                throw new AuthorizationException('The Agent delegation is outside the authorized organization scope.');
            }
        }
        $query = AgentDelegation::query()->where('enterprise_id', $enterprise->getKey());
        foreach (['status', 'source_agent_assignment_id', 'target_agent_assignment_id'] as $field) {
            if (isset($input[$field])) {
                $query->where($field, $input[$field]);
            }
        }
        $limit = min(50, max(1, (int) ($input['limit'] ?? 20)));

        return ['items' => $query->latest('id')->limit($limit)->get()->map(fn (AgentDelegation $d): array => app(GetAgentDelegation::class)->serialize($d))->all(), 'limit' => $limit];
    }
}