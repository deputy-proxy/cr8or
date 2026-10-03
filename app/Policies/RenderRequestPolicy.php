<?php

namespace App\Policies;

use App\Models\RenderRequest;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class RenderRequestPolicy
{
    use HasExplicitCrudContract;

    public function view(User $u, RenderRequest $r): bool
    {
        return (new EnterprisePolicy)->view($u, $r->enterprise);
    }
}
