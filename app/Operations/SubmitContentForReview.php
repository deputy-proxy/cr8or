<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\ContentItem;
use App\Models\User;
use App\Services\ContentItemService;

final class SubmitContentForReview implements Operation
{
    public function __construct(private readonly ContentItemService $content) {}

    public function execute(User $actor, array $input): ContentItem
    {
        $item = $input['content_item'] instanceof ContentItem
            ? $input['content_item']
            : ContentItem::query()->findOrFail((int) $input['content_item_id']);

        return $this->content->submitForReview($actor, $item);
    }
}
