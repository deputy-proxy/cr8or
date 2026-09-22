<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\ExpertDescriptor;
use App\Models\User;

class ExpertDescriptorPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manageable($user);
    }

    public function view(User $user, ExpertDescriptor $descriptor): bool
    {
        return $this->manageable($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ExpertDescriptor $descriptor): bool
    {
        return $this->manageable($user);
    }

    public function delete(User $user, ExpertDescriptor $descriptor): bool
    {
        return false;
    }

    private function manageable(User $user): bool
    {
        return $user->memberships()->whereIn('role', [MembershipRole::Owner->value, MembershipRole::Admin->value])->exists();
    }
}
