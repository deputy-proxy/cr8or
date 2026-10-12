<?php

namespace App\Contracts;

use App\Data\PublishingDeleteResult;
use App\Data\PublishingRemotePublication;

interface PublishingDeleteAdapter extends PublishingAdapter
{
    public function delete(PublishingRemotePublication $publication): PublishingDeleteResult;
}