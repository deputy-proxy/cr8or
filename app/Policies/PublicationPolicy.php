<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\Publication;
use App\Models\User;

/** @property-read \App\Models\Enterprise $enterprise */
class PublicationPolicy
{
    public function view(User $u, Publication $p): bool
    {
        return (new EnterprisePolicy)->view($u, $p->enterprise()->firstOrFail());
    }

    public function create(User $u, Enterprise $e): bool
    {
        return (new EnterprisePolicy)->update($u, $e);
    }

    public function update(User $u, Publication $p): bool
    {
        return (new EnterprisePolicy)->update($u, $p->enterprise()->firstOrFail());
    }

    public function delete(User $u, Publication $p): bool
    {
        return false;
    }
}
