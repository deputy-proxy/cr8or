<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\DomainResourceService;

final class SocialAccountDisable implements Operation
{
    public function __construct(private readonly DomainResourceService $domain) {}

    public function execute(User $actor, array $input): SocialAccount
    {
        $account = ($input['social_account'] ?? null) instanceof SocialAccount
            ? $input['social_account']
            : SocialAccount::query()->with('enterprise')->findOrFail((int) ($input['social_account_id'] ?? 0));

        return $this->domain->disableSocialAccount($actor, $account);
    }
}
