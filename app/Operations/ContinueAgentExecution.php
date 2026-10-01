<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Data\InteractiveReasoningResult;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\InteractiveContinuationService;

final class ContinueAgentExecution implements Operation
{
    public function __construct(private readonly InteractiveContinuationService $continuations) {}

    public function execute(User $actor, array $input): mixed
    {
        $enterprise = $input['enterprise'] ?? Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        return $this->continuations->continue($actor, $enterprise, InteractiveReasoningResult::from($input));
    }
}