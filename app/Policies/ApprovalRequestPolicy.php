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
        return in_array($this->organizationRole($user, $request), [
            MembershipRole::Owner,
            MembershipRole::Admin,
        ], true);
    }

    public function reject(User $user, ApprovalRequest $request): bool
    {
        return $this->approve($user, $request);
    }

    private function organizationRole(User $user, ApprovalRequest $request): ?MembershipRole
    {
        return $user->memberships()
            ->where('organization_id', $request->organization_id)
            ->value('role');
    }
}
