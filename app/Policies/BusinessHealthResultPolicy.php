<?php

namespace App\Policies;

use App\Models\BusinessHealthResult;
use App\Models\Enterprise;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class BusinessHealthResultPolicy
{
    use HasExplicitCrudContract;

    /**
     * This record is changed only through its domain lifecycle operation.
     */
    public function delete(User $user, BusinessHealthResult $record): bool
    {
        return false;
    }

    /**
     * This record is changed only through its domain lifecycle operation.
     */
    public function update(User $user, BusinessHealthResult $record): bool
    {
        return false;
    }

    public function view(User $user, BusinessHealthResult $result): bool
    {
        return $this->enterprisePolicy()->view($user, $result->enterprise);
    }

    /**
     * This record is created through its domain operation, not generic Filament CRUD.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function createForEnterprise(User $user, Enterprise $enterprise): bool
    {
        return $this->enterprisePolicy()->createForOrganization($user, $enterprise->organization);
    }

    private function enterprisePolicy(): EnterprisePolicy
    {
        return new EnterprisePolicy;
    }
}
