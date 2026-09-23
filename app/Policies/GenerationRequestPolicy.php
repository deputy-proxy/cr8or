<?php

namespace App\Policies;

use App\Models\GenerationRequest;
use App\Models\User;

class GenerationRequestPolicy
{
    public function view(User $u, GenerationRequest $r): bool
    {
        return (new EnterprisePolicy)->view($u, $r->enterprise);
    }
}