<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\ContentSeries;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class ContentSeriesPolicy
{
    use HasExplicitCrudContract;

    public function view(User $user, ContentSeries $series): bool
    {
        return (new EnterprisePolicy)->view($user, $series->campaign->enterprise);
    }

    public function create(User $user): bool
    {
        return (new EnterprisePolicy)->create($user);
    }

    public function createForCampaign(User $user, Campaign $campaign): bool
    {
        return (new EnterprisePolicy)->createForOrganization($user, $campaign->enterprise->organization);
    }

    public function update(User $user, ContentSeries $series): bool
    {
        return (new EnterprisePolicy)->update($user, $series->campaign->enterprise);
    }

    public function delete(User $user, ContentSeries $series): bool
    {
        return (new EnterprisePolicy)->delete($user, $series->campaign->enterprise);
    }
}
