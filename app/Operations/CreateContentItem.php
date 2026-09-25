<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\ContentItem;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\ContentItemService;

final class CreateContentItem implements Operation
{
    public function __construct(private readonly ContentItemService $content) {}

    public function execute(User $actor, array $input): ContentItem
    {
        return $this->content->create(
            $actor,
            $input['enterprise'] instanceof Enterprise
                ? $input['enterprise']
                : Enterprise::query()->findOrFail((int) $input['enterprise_id']),
            $input,
        );
    }
}
