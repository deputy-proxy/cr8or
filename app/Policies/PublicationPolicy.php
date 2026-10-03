<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\Publication;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

/** @property-read \App\Models\Enterprise $enterprise */
class PublicationPolicy
{
    use HasExplicitCrudContract;

    public function view(User $u, Publication $p): bool
    {
        return (new EnterprisePolicy)->view($u, $p->enterprise()->firstOrFail());
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function createForEnterprise(User $u, Enterprise $e): bool
    {
        return (new EnterprisePolicy)->update($u, $e);
    }

    public function update(User $user, object $record): bool
    {
        return false;
    }

    public function delete(User $user, object $record): bool
    {
        return false;
    }
}
