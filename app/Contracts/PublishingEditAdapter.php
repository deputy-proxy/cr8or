<?php

namespace App\Contracts;

use App\Data\PublishingAdapterResult;
use App\Data\PublishingEditRequest;

interface PublishingEditAdapter extends PublishingAdapter
{
    public function edit(PublishingEditRequest $request): PublishingAdapterResult;
}