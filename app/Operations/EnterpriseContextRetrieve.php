<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\EnterpriseContextService;

final class EnterpriseContextRetrieve implements Operation
{
    public function __construct(
        private readonly EnterpriseContextService $context,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function execute(User $actor, array $input): array
    {
        $enterprise = isset($input['enterprise']) && $input['enterprise'] instanceof Enterprise
            ? $input['enterprise']
            : Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        return $this->context->retrieve($actor, $enterprise);
    }
}