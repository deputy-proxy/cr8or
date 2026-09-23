<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\User;
use App\Models\Workflow;

class WorkflowPolicy
{
    public function view(User $user, Workflow $record): bool
    {
        return $this->organizationRole($user, $record) !== null;
    }

    private function organizationRole(User $user, Workflow $record): ?MembershipRole
    {
        return $user->memberships()
            ->where('organization_id', $record->enterprise->organization_id)
            ->value('role');
    }
}
