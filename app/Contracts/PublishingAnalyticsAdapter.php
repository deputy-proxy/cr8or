<?php

namespace App\Contracts;

use App\Data\PublishingAnalyticsResult;
use App\Data\PublishingRemotePublication;

interface PublishingAnalyticsAdapter extends PublishingAdapter
{
    public function fetchAnalytics(PublishingRemotePublication $publication): PublishingAnalyticsResult;
}