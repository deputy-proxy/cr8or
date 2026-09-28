<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\KnowledgeIndexRecord;
use App\Models\User;
use App\Services\KnowledgeResourceService;

final class UpdateKnowledgeIndex implements Operation
{
    public function __construct(private readonly KnowledgeResourceService $resources) {}

    public function execute(User $actor, array $input): mixed
    {
        $enterprise = isset($input['enterprise']) && $input['enterprise'] instanceof Enterprise
            ? $input['enterprise']
            : Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        $record = isset($input['knowledge_index']) && $input['knowledge_index'] instanceof KnowledgeIndexRecord
            ? $input['knowledge_index']
            : KnowledgeIndexRecord::query()->findOrFail((int) $input['knowledge_index_id']);

        return $this->resources->serializeIndex($this->resources->updateIndex($actor, $enterprise, $record, $input['correlation_id'] ?? null));
    }
}