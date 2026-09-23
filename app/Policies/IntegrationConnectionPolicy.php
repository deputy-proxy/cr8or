<?php

namespace App\Policies;

use App\Models\IntegrationConnection;
use App\Models\User;

class IntegrationConnectionPolicy
{
    public function view(User $user, IntegrationConnection $connection): bool
    {
        return (new OrganizationPolicy)->view($user, $connection->organization);
    }

    public function create(User $user): bool
    {
        return $user->exists;
    }

    public function update(User $user, IntegrationConnection $connection): bool
    {
        return (new OrganizationPolicy)->update($user, $connection->organization);
    }

    public function delete(User $user, IntegrationConnection $connection): bool
    {
        return (new OrganizationPolicy)->update($user, $connection->organization);
    }
}
