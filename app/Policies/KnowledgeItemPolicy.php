<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\KnowledgeItem;
use App\Models\User;

class KnowledgeItemPolicy
{
    public function view(User $user, KnowledgeItem $record): bool
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

    public function update(User $user, KnowledgeItem $record): bool
    {
        return (new EnterprisePolicy)->update($user, $record->enterprise);
    }

    public function delete(User $user, KnowledgeItem $record): bool
    {
        return (new EnterprisePolicy)->delete($user, $record->enterprise);
    }
}