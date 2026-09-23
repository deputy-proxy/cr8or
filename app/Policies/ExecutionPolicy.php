<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\Execution;
use App\Models\User;

class ExecutionPolicy
{
    public function view(User $user, Execution $record): bool
    {
        return $this->organizationRole($user, $record) !== null;
    }

    private function organizationRole(User $user, Execution $record): ?MembershipRole
    {
        return $user->memberships()
            ->where('organization_id', $record->organization_id)
            ->value('role');
    }
}
