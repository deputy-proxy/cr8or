<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\MemoryResourceService;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class RetrieveMemory implements Operation
{
    public function __construct(private readonly MemoryResourceService $memory) {}

    public function execute(User $actor, array $input): mixed
    {
        $enterprise = $input['enterprise'] ?? Enterprise::query()->findOrFail((int) $input['enterprise_id']);
        $agent = AgentDescriptor::query()->findOrFail((int) $input['agent_descriptor_id']);

        if (isset($input['agent_assignment_id'])) {
            $assignment = AgentAssignment::query()->findOrFail((int) $input['agent_assignment_id']);

            if ((int) $assignment->enterprise_id !== (int) $enterprise->getKey() || (int) $assignment->agent_descriptor_id !== (int) $agent->getKey()) {
                throw new InvalidArgumentException('Memory retrieval Agent assignment does not match the requested Enterprise and Agent.');
            }
        }

        return $this->memory->retrieve(
            $actor,
            $enterprise,
            $agent,
            $input['topic'] ?? null,
            isset($input['relevant_after']) ? Carbon::parse((string) $input['relevant_after']) : null,
            (int) ($input['episodic_limit'] ?? 25),
            $input['semantic_status'] ?? null,
            (int) ($input['semantic_limit'] ?? 50),
        );
    }
}