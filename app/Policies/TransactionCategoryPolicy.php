<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\TransactionCategory;
use App\Models\User;

class TransactionCategoryPolicy
{
    public function view(User $user, TransactionCategory $category): bool
    {
        return $this->enterprisePolicy()->view($user, $category->enterprise);
    }

    public function create(User $user, Enterprise $enterprise): bool
    {
        return $this->enterprisePolicy()->create($user, $enterprise->organization);
    }

    public function update(User $user, TransactionCategory $category): bool
    {
        return $this->enterprisePolicy()->update($user, $category->enterprise);
    }

    public function delete(User $user, TransactionCategory $category): bool
    {
        return $this->enterprisePolicy()->delete($user, $category->enterprise);
    }

    private function enterprisePolicy(): EnterprisePolicy
    {
        return new EnterprisePolicy;
    }
}
