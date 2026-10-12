<?php

namespace App\Policies;

use App\Models\SocialCredential;
use App\Models\User;

/** Credential payloads are an internal secret boundary, never a generic CRUD surface. */
final class SocialCredentialPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, SocialCredential $credential): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SocialCredential $credential): bool
    {
        return false;
    }

    public function delete(User $user, SocialCredential $credential): bool
    {
        return false;
    }
}