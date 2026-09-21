<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\EnterpriseContext;
use App\Models\User;

class EnterpriseContextPolicy
{
    public function view(User $user, EnterpriseContext $context): bool
    {
        return $this->enterprisePolicy()->view($user, $context->enterprise);
    }

    public function create(User $user, Enterprise $enterprise): bool
    {
        return $this->enterprisePolicy()->create($user, $enterprise->organization);
    }

    public function update(User $user, EnterpriseContext $context): bool
    {
        return $this->enterprisePolicy()->update($user, $context->enterprise);
    }

    public function delete(User $user, EnterpriseContext $context): bool
    {
        return $this->enterprisePolicy()->delete($user, $context->enterprise);
    }

    private function enterprisePolicy(): EnterprisePolicy
    {
        return new EnterprisePolicy;
    }
}