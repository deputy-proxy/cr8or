<?php

namespace App\Contracts;

use App\Data\PublishingAdapterResult;
use App\Data\PublishingRemotePublication;

interface PublishingStatusLookupAdapter extends PublishingAdapter
{
    /** Return null when the provider cannot currently determine the remote status. */
    public function lookupStatus(PublishingRemotePublication $publication): ?PublishingAdapterResult;
}