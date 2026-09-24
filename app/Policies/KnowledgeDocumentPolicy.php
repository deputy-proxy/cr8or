<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\KnowledgeDocument;
use App\Models\User;

class KnowledgeDocumentPolicy
{
    public function view(User $user, KnowledgeDocument $record): bool
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

    public function update(User $user, KnowledgeDocument $record): bool
    {
        return (new EnterprisePolicy)->update($user, $record->enterprise);
    }

    public function delete(User $user, KnowledgeDocument $record): bool
    {
        return (new EnterprisePolicy)->delete($user, $record->enterprise);
    }
}
