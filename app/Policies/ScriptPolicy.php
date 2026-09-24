<?php

namespace App\Policies;

use App\Models\ContentItem;
use App\Models\Script;
use App\Models\User;

class ScriptPolicy
{
    public function view(User $user, Script $script): bool
    {
        return (new EnterprisePolicy)->view($user, $script->contentItem->enterprise);
    }

    public function create(User $user): bool
    {
        return (new EnterprisePolicy)->create($user);
    }

    public function createForContentItem(User $user, ContentItem $item): bool
    {
        return (new EnterprisePolicy)->createForOrganization($user, $item->enterprise->organization);
    }

    public function update(User $user, Script $script): bool
    {
        return (new EnterprisePolicy)->update($user, $script->contentItem->enterprise);
    }

    public function delete(User $user, Script $script): bool
    {
        return (new EnterprisePolicy)->delete($user, $script->contentItem->enterprise);
    }
}
