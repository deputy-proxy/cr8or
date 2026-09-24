<?php

namespace App\Policies;

use App\Models\Channel;
use App\Models\Enterprise;
use App\Models\User;

class ChannelPolicy
{
    public function view(User $user, Channel $channel): bool
    {
        return (new EnterprisePolicy)->view($user, $channel->enterprise);
    }

    public function create(User $user): bool
    {
        return (new EnterprisePolicy)->create($user);
    }

    public function createForEnterprise(User $user, Enterprise $enterprise): bool
    {
        return (new EnterprisePolicy)->createForOrganization($user, $enterprise->organization);
    }

    public function update(User $user, Channel $channel): bool
    {
        return (new EnterprisePolicy)->update($user, $channel->enterprise);
    }

    public function delete(User $user, Channel $channel): bool
    {
        return (new EnterprisePolicy)->delete($user, $channel->enterprise);
    }
}