<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\Statement;
use App\Models\User;

class StatementPolicy
{
    public function view(User $user, Statement $statement): bool
    {
        return $this->enterprisePolicy()->view($user, $statement->enterprise);
    }

    public function create(User $user): bool
    {
        return (new EnterprisePolicy)->create($user);
    }

    public function createForEnterprise(User $user, Enterprise $enterprise): bool
    {
        return $this->enterprisePolicy()->createForOrganization($user, $enterprise->organization);
    }

    public function update(User $user, Statement $statement): bool
    {
        return $this->enterprisePolicy()->update($user, $statement->enterprise);
    }

    public function delete(User $user, Statement $statement): bool
    {
        return $this->enterprisePolicy()->delete($user, $statement->enterprise);
    }

    private function enterprisePolicy(): EnterprisePolicy
    {
        return new EnterprisePolicy;
    }
}
