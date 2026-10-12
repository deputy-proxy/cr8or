<?php

namespace App\Policies;

use App\Models\SocialAuthorizationAudit;
use App\Models\User;

/** Audit records are append-only internal provenance, not a generic CRUD surface. */
final class SocialAuthorizationAuditPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, SocialAuthorizationAudit $audit): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SocialAuthorizationAudit $audit): bool
    {
        return false;
    }

    public function delete(User $user, SocialAuthorizationAudit $audit): bool
    {
        return false;
    }
}