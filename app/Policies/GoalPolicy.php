<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\Goal;
use App\Models\User;

class GoalPolicy
{
    public function view(User $user, Goal $goal): bool
    {
        return $this->enterprisePolicy()->view($user, $goal->enterprise);
    }

    public function create(User $user, Enterprise $enterprise): bool
    {
        return $this->enterprisePolicy()->create($user, $enterprise->organization);
    }

    public function update(User $user, Goal $goal): bool
    {
        return $this->enterprisePolicy()->update($user, $goal->enterprise);
    }

    public function delete(User $user, Goal $goal): bool
    {
        return $this->enterprisePolicy()->delete($user, $goal->enterprise);
    }

    private function enterprisePolicy(): EnterprisePolicy
    {
        return new EnterprisePolicy;
    }
}