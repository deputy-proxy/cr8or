<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\KnowledgeItemService;

final class ListKnowledgeItems implements Operation
{
    public function __construct(private readonly KnowledgeItemService $items) {}

    public function execute(User $actor, array $input): mixed
    {
        $enterprise = $input['enterprise'] ?? Enterprise::query()->findOrFail((int) $input['enterprise_id']);
        $page = $this->items->list($actor, $enterprise, $input['search'] ?? null, $input['type'] ?? null, (int) ($input['per_page'] ?? 25), (int) ($input['page'] ?? 1));

        return [
            'items' => $page->getCollection()->map(fn ($item): array => $this->items->serialize($item, false))->values()->all(),
            'pagination' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ];
    }
}
