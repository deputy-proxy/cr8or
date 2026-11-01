<?php

namespace App\Policies;

use App\Models\IntegrationRepository;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class IntegrationRepositoryPolicy
{
    use HasExplicitCrudContract;

    public function view(User $user, IntegrationRepository $repository): bool
    {
        return (new IntegrationConnectionPolicy)->view($user, $repository->connection);
    }

    public function update(User $user, IntegrationRepository $repository): bool
    {
        if (! (new IntegrationConnectionPolicy)->update($user, $repository->connection)) {
            return false;
        }

        return $repository->enterprise === null || (new EnterprisePolicy)->update($user, $repository->enterprise);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, IntegrationRepository $repository): bool
    {
        return false;
    }
}
