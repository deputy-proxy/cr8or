<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\KnowledgeItem;
use App\Models\User;
use App\Services\KnowledgeItemService;

final class GetKnowledgeItem implements Operation
{
    public function __construct(private readonly KnowledgeItemService $items) {}

    public function execute(User $actor, array $input): mixed
    {
        $enterprise = $input['enterprise'] ?? Enterprise::query()->findOrFail((int) $input['enterprise_id']);
        $item = KnowledgeItem::query()->where('enterprise_id', $enterprise->getKey())->whereKey((int) $input['knowledge_item_id'])->firstOrFail();

        return $this->items->get($actor, $enterprise, $item);
    }
}
