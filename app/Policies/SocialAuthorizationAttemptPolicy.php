<?php

namespace App\Policies;

use App\Models\SocialAuthorizationAttempt;
use App\Models\User;

/** Authorization attempts are mutated only through the authorization lifecycle service. */
final class SocialAuthorizationAttemptPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, SocialAuthorizationAttempt $attempt): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SocialAuthorizationAttempt $attempt): bool
    {
        return false;
    }

    public function delete(User $user, SocialAuthorizationAttempt $attempt): bool
    {
        return false;
    }
}