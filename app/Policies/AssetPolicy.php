<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\Enterprise;
use App\Models\User;

class AssetPolicy
{
    public function view(User $u, Asset $a): bool
    {
        return (new EnterprisePolicy)->view($u, $a->enterprise);
    }

    public function create(User $u, Enterprise $e): bool
    {
        return (new EnterprisePolicy)->create($u, $e->organization);
    }

    public function update(User $u, Asset $a): bool
    {
        return (new EnterprisePolicy)->update($u, $a->enterprise);
    }

    public function delete(User $u, Asset $a): bool
    {
        return (new EnterprisePolicy)->delete($u, $a->enterprise);
    }
}