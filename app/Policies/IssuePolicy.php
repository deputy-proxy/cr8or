<?php

namespace App\Policies;

use App\Models\Issue;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class IssuePolicy
{
    use HasExplicitCrudContract;

    public function view(User $user, Issue $issue): bool
    {
        return (new EnterprisePolicy)->view($user, $issue->enterprise);
    }
}
