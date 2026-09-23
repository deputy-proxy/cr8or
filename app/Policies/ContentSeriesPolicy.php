<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\ContentSeries;
use App\Models\User;

class ContentSeriesPolicy
{
    public function view(User $user, ContentSeries $series): bool
    {
        return (new EnterprisePolicy)->view($user, $series->campaign->enterprise);
    }

    public function create(User $user, Campaign $campaign): bool
    {
        return (new EnterprisePolicy)->create($user, $campaign->enterprise->organization);
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
