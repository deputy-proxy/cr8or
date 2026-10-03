<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Organization;
use App\Models\User;
use App\Services\DomainResourceService;

final class EnterpriseCreate implements Operation
{
    public function __construct(private readonly DomainResourceService $domain) {}

    /** @param array<string, mixed> $input */
    public function execute(User $actor, array $input): mixed
    {
        $organization = Organization::query()->findOrFail((int) $input['organization_id']);

        return $this->domain->createEnterprise($actor, $organization, $input);
    }
}