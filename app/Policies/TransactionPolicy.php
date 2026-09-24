<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\Transaction;
use App\Models\User;

class TransactionPolicy
{
    public function view(User $user, Transaction $transaction): bool
    {
        return $this->enterprisePolicy()->view($user, $transaction->enterprise);
    }

    public function create(User $user): bool
    {
        return (new EnterprisePolicy)->create($user);
    }

    public function createForEnterprise(User $user, Enterprise $enterprise): bool
    {
        return $this->enterprisePolicy()->createForOrganization($user, $enterprise->organization);
    }

    public function update(User $user, Transaction $transaction): bool
    {
        return $this->enterprisePolicy()->update($user, $transaction->enterprise);
    }

    public function delete(User $user, Transaction $transaction): bool
    {
        return $this->enterprisePolicy()->delete($user, $transaction->enterprise);
    }

    private function enterprisePolicy(): EnterprisePolicy
    {
        return new EnterprisePolicy;
    }
}