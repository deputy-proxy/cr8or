<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\KnowledgeSource;
use App\Models\User;

class KnowledgeSourcePolicy
{
    public function view(User $user, KnowledgeSource $record): bool
    {
        return (new EnterprisePolicy)->view($user, $record->enterprise);
    }

    public function create(User $user, Enterprise $enterprise): bool
    {
        return (new EnterprisePolicy)->create($user, $enterprise->organization);
    }

    public function update(User $user, KnowledgeSource $record): bool
    {
        return (new EnterprisePolicy)->update($user, $record->enterprise);
    }

    public function delete(User $user, KnowledgeSource $record): bool
    {
        return (new EnterprisePolicy)->delete($user, $record->enterprise);
    }
}
