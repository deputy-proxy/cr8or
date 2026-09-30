<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\ContentItem;
use App\Models\Script;
use App\Models\User;
use App\Services\DomainResourceService;

final class CreateScript implements Operation
{
    public function __construct(private readonly DomainResourceService $domain) {}

    public function execute(User $actor, array $input): Script
    {
        $item = isset($input['content_item']) && $input['content_item'] instanceof ContentItem
            ? $input['content_item']
            : ContentItem::query()->findOrFail((int) $input['content_item_id']);

        return $this->domain->createScript($actor, $item, $input);
    }
}