<?php

namespace App\Policies;

use App\Models\LifecycleSnapshot;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class LifecycleSnapshotPolicy
{
    use HasExplicitCrudContract;

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, LifecycleSnapshot $snapshot): bool
    {
        return (new EnterprisePolicy)->view($user, $snapshot->enterprise);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, LifecycleSnapshot $snapshot): bool
    {
        return false;
    }

    public function delete(User $user, LifecycleSnapshot $snapshot): bool
    {
        return false;
    }
}
