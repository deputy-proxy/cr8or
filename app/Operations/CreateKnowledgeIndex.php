<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\KnowledgeItem;
use App\Models\User;
use App\Services\KnowledgeResourceService;

final class CreateKnowledgeIndex implements Operation
{
    public function __construct(private readonly KnowledgeResourceService $resources) {}

    public function execute(User $actor, array $input): mixed
    {
        $enterprise = isset($input['enterprise']) && $input['enterprise'] instanceof Enterprise
            ? $input['enterprise']
            : Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        $item = isset($input['knowledge_item']) && $input['knowledge_item'] instanceof KnowledgeItem
            ? $input['knowledge_item']
            : KnowledgeItem::query()->findOrFail((int) $input['knowledge_item_id']);

        return $this->resources->createIndex($actor, $enterprise, $item, $input['correlation_id'] ?? null);
    }
}