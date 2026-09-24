<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\Partner;
use App\Models\User;

class PartnerPolicy
{
    public function view(User $user, Partner $partner): bool
    {
        return $this->enterprisePolicy()->view($user, $partner->enterprise);
    }

    public function create(User $user): bool
    {
        return (new EnterprisePolicy)->create($user);
    }

    public function createForEnterprise(User $user, Enterprise $enterprise): bool
    {
        return $this->enterprisePolicy()->createForOrganization($user, $enterprise->organization);
    }

    public function update(User $user, Partner $partner): bool
    {
        return $this->enterprisePolicy()->update($user, $partner->enterprise);
    }

    public function delete(User $user, Partner $partner): bool
    {
        return $this->enterprisePolicy()->delete($user, $partner->enterprise);
    }

    private function enterprisePolicy(): EnterprisePolicy
    {
        return new EnterprisePolicy;
    }
}