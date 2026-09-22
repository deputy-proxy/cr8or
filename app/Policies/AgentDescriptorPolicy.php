<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\AgentDescriptor;
use App\Models\User;

class AgentDescriptorPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manageable($user);
    }

    public function view(User $user, AgentDescriptor $descriptor): bool
    {
        return $this->manageable($user);
    }

    public function create(User $user): bool
    {
        return $this->manageable($user);
    }

    public function update(User $user, AgentDescriptor $descriptor): bool
    {
        return $this->manageable($user);
    }

    public function delete(User $user, AgentDescriptor $descriptor): bool
    {
        return $this->manageable($user);
    }

    private function manageable(User $user): bool
    {
        return $user->memberships()->whereIn('role', [MembershipRole::Owner->value, MembershipRole::Admin->value])->exists();
    }
}
