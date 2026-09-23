<?php

namespace App\Policies;

use App\Models\RenderRequest;
use App\Models\User;

class RenderRequestPolicy
{
    public function view(User $u, RenderRequest $r): bool
    {
        return (new EnterprisePolicy)->view($u, $r->enterprise);
    }
}
