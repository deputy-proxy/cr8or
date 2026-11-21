<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\KnowledgeItemService;

final class CreateKnowledgeItem implements Operation
{
    public function __construct(private readonly KnowledgeItemService $items) {}

    public function execute(User $actor, array $input): mixed
    {
        $enterprise = $input['enterprise'] ?? Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        return $this->items->create($actor, $enterprise, $input);
    }
}
