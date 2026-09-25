<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\ContentItem;
use App\Models\User;
use App\Services\ContentItemService;

final class UpdateContentItem implements Operation
{
    public function __construct(private readonly ContentItemService $content) {}

    public function execute(User $actor, array $input): ContentItem
    {
        $item = $input['content_item'] instanceof ContentItem
            ? $input['content_item']
            : ContentItem::query()->findOrFail((int) $input['content_item_id']);

        $attributes = $input['attributes'] ?? array_intersect_key(
            $input,
            array_flip(['title', 'body', 'agent_execution_id', 'agent_decision_id']),
        );

        return $this->content->update($actor, $item, $attributes);
    }
}
