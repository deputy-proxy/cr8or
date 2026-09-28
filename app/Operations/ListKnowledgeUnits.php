<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\KnowledgeResourceService;

final class ListKnowledgeUnits implements Operation
{
    public function __construct(private readonly KnowledgeResourceService $resources) {}

    public function execute(User $actor, array $input): mixed
    {
        $enterprise = isset($input['enterprise']) && $input['enterprise'] instanceof Enterprise
            ? $input['enterprise']
            : Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        $page = $this->resources->listUnits(
            $actor,
            $enterprise,
            isset($input['knowledge_index_id']) ? (int) $input['knowledge_index_id'] : null,
            isset($input['knowledge_item_id']) ? (int) $input['knowledge_item_id'] : null,
            (int) ($input['per_page'] ?? 25),
        );

        return [
            'items' => $page->getCollection()->map(fn ($unit): array => $this->resources->serializeUnit($unit))->values()->all(),
            'pagination' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }
}