<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\KnowledgeReference;
use App\Models\User;

class KnowledgeReferencePolicy
{
    public function view(User $user, KnowledgeReference $record): bool
    {
        return (new EnterprisePolicy)->view($user, $record->enterprise);
    }

    public function create(User $user, Enterprise $enterprise): bool
    {
        return (new EnterprisePolicy)->create($user, $enterprise->organization);
    }

    public function update(User $user, KnowledgeReference $record): bool
    {
        return (new EnterprisePolicy)->update($user, $record->enterprise);
    }

    public function delete(User $user, KnowledgeReference $record): bool
    {
        return (new EnterprisePolicy)->delete($user, $record->enterprise);
    }
}
