<?php

namespace App\Policies;

use App\Models\GenerationRequest;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class GenerationRequestPolicy
{
    use HasExplicitCrudContract;

    public function view(User $u, GenerationRequest $r): bool
    {
        return (new EnterprisePolicy)->view($u, $r->enterprise);
    }
}
