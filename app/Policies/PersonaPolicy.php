<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\Persona;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class PersonaPolicy
{
    use HasExplicitCrudContract;

    public function view(User $user, Persona $persona): bool
    {
        return (new EnterprisePolicy)->view($user, $persona->enterprise);
    }

    public function create(User $user): bool
    {
        return (new EnterprisePolicy)->create($user);
    }

    public function createForEnterprise(User $user, Enterprise $enterprise): bool
    {
        return (new EnterprisePolicy)->createForOrganization($user, $enterprise->organization);
    }

    public function update(User $user, Persona $persona): bool
    {
        return (new EnterprisePolicy)->update($user, $persona->enterprise);
    }

    public function delete(User $user, Persona $persona): bool
    {
        return (new EnterprisePolicy)->delete($user, $persona->enterprise);
    }
}