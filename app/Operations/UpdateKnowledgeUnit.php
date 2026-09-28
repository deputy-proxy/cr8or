<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\KnowledgeIndexUnit;
use App\Models\User;
use App\Services\KnowledgeResourceService;

final class UpdateKnowledgeUnit implements Operation
{
    public function __construct(private readonly KnowledgeResourceService $resources) {}

    public function execute(User $actor, array $input): mixed
    {
        $enterprise = isset($input['enterprise']) && $input['enterprise'] instanceof Enterprise
            ? $input['enterprise']
            : Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        $unit = isset($input['knowledge_unit']) && $input['knowledge_unit'] instanceof KnowledgeIndexUnit
            ? $input['knowledge_unit']
            : KnowledgeIndexUnit::query()->findOrFail((int) $input['knowledge_unit_id']);

        return $this->resources->serializeUnit($this->resources->updateUnit($actor, $enterprise, $unit, $input['correlation_id'] ?? null));
    }
}