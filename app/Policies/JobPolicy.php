<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\Job;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class JobPolicy
{
    use HasExplicitCrudContract;

    public function view(User $user, Job $record): bool
    {
        return $this->organizationRole($user, $record) !== null;
    }

    private function organizationRole(User $user, Job $record): ?MembershipRole
    {
        return $user->memberships()
            ->where('organization_id', $record->workflow->enterprise->organization_id)
            ->value('role');
    }
}
