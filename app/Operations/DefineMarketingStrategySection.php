<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\MarketingStrategySectionService;

final class DefineMarketingStrategySection implements Operation
{
    public function __construct(private readonly MarketingStrategySectionService $sections) {}

    /** @return array<string, mixed> */
    public function execute(User $actor, array $input): array
    {
        $enterprise = $input['enterprise'] instanceof Enterprise
            ? $input['enterprise']
            : Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        return $this->sections->define(
            $actor,
            $enterprise,
            (string) $input['section_key'],
            is_array($input['context'] ?? null) ? $input['context'] : [],
        );
    }
}