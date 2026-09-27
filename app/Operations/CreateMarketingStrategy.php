<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\MarketingStrategy;
use App\Services\DomainResourceService;
use App\Models\User;

final class CreateMarketingStrategy implements Operation
{
    public function __construct(private readonly DomainResourceService $domain) {}

    public function execute(User $actor, array $input): MarketingStrategy
    {
        $enterprise = $input['enterprise'] instanceof Enterprise
            ? $input['enterprise']
            : Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        return $this->domain->createMarketingStrategy($actor, $enterprise, $input);
    }
}
