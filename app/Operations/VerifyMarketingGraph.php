<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\MarketingGraphVerificationService;

final class VerifyMarketingGraph implements Operation
{
    public function __construct(private readonly MarketingGraphVerificationService $verification) {}

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function execute(User $actor, array $input): array
    {
        $enterprise = $input['enterprise'] instanceof Enterprise
            ? $input['enterprise']
            : Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        return $this->verification->verify($actor, $enterprise, $input);
    }
}
