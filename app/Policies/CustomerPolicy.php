<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\Enterprise;
use App\Models\User;

class CustomerPolicy
{
    public function view(User $user, Customer $customer): bool
    {
        return $this->enterprisePolicy()->view($user, $customer->enterprise);
    }

    public function create(User $user, Enterprise $enterprise): bool
    {
        return $this->enterprisePolicy()->create($user, $enterprise->organization);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->enterprisePolicy()->update($user, $customer->enterprise);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->enterprisePolicy()->delete($user, $customer->enterprise);
    }

    private function enterprisePolicy(): EnterprisePolicy
    {
        return new EnterprisePolicy;
    }
}