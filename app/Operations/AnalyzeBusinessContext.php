<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\ExpertCapabilityService;

final class AnalyzeBusinessContext implements Operation
{
    public function __construct(private readonly ExpertCapabilityService $experts) {}

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function execute(User $actor, array $input): array
    {
        return $this->experts->execute(
            $actor,
            $input['enterprise'] instanceof Enterprise
                ? $input['enterprise']
                : Enterprise::query()->findOrFail((int) $input['enterprise_id']),
            'business-analysis',
            'business.analysis',
            $input['target_context'] ?? [],
        );
    }
}
