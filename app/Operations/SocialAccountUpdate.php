<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\DomainResourceService;
use Illuminate\Validation\ValidationException;

final class SocialAccountUpdate implements Operation
{
    public function __construct(private readonly DomainResourceService $domain) {}

    public function execute(User $actor, array $input): SocialAccount
    {
        $account = ($input['social_account'] ?? null) instanceof SocialAccount
            ? $input['social_account']
            : SocialAccount::query()->with('enterprise')->findOrFail((int) ($input['social_account_id'] ?? 0));

        $attributes = array_intersect_key($input, array_flip(['name', 'channel_id']));
        if ($attributes === []) {
            throw ValidationException::withMessages([
                'input' => 'At least one mutable social account field is required.',
            ]);
        }

        return $this->domain->updateSocialAccount($actor, $account, $attributes);
    }
}
