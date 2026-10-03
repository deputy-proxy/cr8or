<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\Enterprise;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class CampaignPolicy
{
    use HasExplicitCrudContract;

    public function view(User $user, Campaign $campaign): bool
    {
        return (new EnterprisePolicy)->view($user, $campaign->enterprise);
    }

    public function create(User $user): bool
    {
        return (new EnterprisePolicy)->create($user);
    }

    public function createForEnterprise(User $user, Enterprise $enterprise): bool
    {
        return (new EnterprisePolicy)->createForOrganization($user, $enterprise->organization);
    }

    public function update(User $user, Campaign $campaign): bool
    {
        return (new EnterprisePolicy)->update($user, $campaign->enterprise);
    }

    public function delete(User $user, Campaign $campaign): bool
    {
        return (new EnterprisePolicy)->delete($user, $campaign->enterprise);
    }
}
