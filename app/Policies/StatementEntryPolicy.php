<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\StatementEntry;
use App\Models\User;

class StatementEntryPolicy
{
    public function view(User $user, StatementEntry $entry): bool
    {
        return $this->enterprisePolicy()->view($user, $entry->enterprise);
    }

    public function create(User $user): bool
    {
        return (new EnterprisePolicy)->create($user);
    }

    public function createForEnterprise(User $user, Enterprise $enterprise): bool
    {
        return $this->enterprisePolicy()->createForOrganization($user, $enterprise->organization);
    }

    public function update(User $user, StatementEntry $entry): bool
    {
        return $this->enterprisePolicy()->update($user, $entry->enterprise);
    }

    public function delete(User $user, StatementEntry $entry): bool
    {
        return $this->enterprisePolicy()->delete($user, $entry->enterprise);
    }

    private function enterprisePolicy(): EnterprisePolicy
    {
        return new EnterprisePolicy;
    }
}