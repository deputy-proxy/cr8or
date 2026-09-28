<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\KnowledgeResourceService;

final class ListKnowledgeIndexes implements Operation
{
    public function __construct(private readonly KnowledgeResourceService $resources) {}

    public function execute(User $actor, array $input): mixed
    {
        $enterprise = isset($input['enterprise']) && $input['enterprise'] instanceof Enterprise
            ? $input['enterprise']
            : Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        $page = $this->resources->listIndexes(
            $actor,
            $enterprise,
            isset($input['knowledge_item_id']) ? (int) $input['knowledge_item_id'] : null,
            $input['status'] ?? null,
            $input['search'] ?? null,
            (int) ($input['per_page'] ?? 25),
        );

        return [
            'items' => $page->getCollection()->map(fn ($record): array => $this->resources->serializeIndex($record))->values()->all(),
            'pagination' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }
}