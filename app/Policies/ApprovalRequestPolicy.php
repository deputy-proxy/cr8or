<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\ApprovalRequest;
use App\Models\User;

class ApprovalRequestPolicy
{
    public function view(User $user, ApprovalRequest $request): bool
    {
        return $this->organizationRole($user, $request) !== null;
    }

    public function approve(User $user, ApprovalRequest $request): bool
    {
        return in_array($this->organizationRole($user, $request), ['owner', 'admin'], true);
    }

    public function reject(User $user, ApprovalRequest $request): bool
    {
        return $this->approve($user, $request);
    }

    public function cancel(User $user, ApprovalRequest $request): bool
    {
        $role = $this->organizationRole($user, $request);

        return $role !== null && (
            (int) $request->actor_id === (int) $user->getKey()
            || in_array($role, ['owner', 'admin'], true)
        );
    }

    private function organizationRole(User $user, ApprovalRequest $request): ?string
    {
        $role = $user->memberships()
            ->where('organization_id', $request->organization_id)
            ->value('role');

        return $role instanceof MembershipRole ? $role->value : ($role === null ? null : (string) $role);
    }
}