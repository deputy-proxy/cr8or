<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\KnowledgeVersion;
use App\Models\User;

class KnowledgeVersionPolicy
{
    public function view(User $user, KnowledgeVersion $record): bool
    {
        return (new EnterprisePolicy)->view($user, $record->enterprise);
    }

    public function create(User $user): bool
    {
        return (new EnterprisePolicy)->create($user);
    }

    public function createForEnterprise(User $user, Enterprise $enterprise): bool
    {
        return (new EnterprisePolicy)->createForOrganization($user, $enterprise->organization);
    }

    public function update(User $user, KnowledgeVersion $record): bool
    {
        return false;
    }

    public function delete(User $user, KnowledgeVersion $record): bool
    {
        return false;
    }
}