<?php

namespace App\Mcp\Tools;

use App\Models\Campaign;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\DomainResourceService;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('transition-campaign')]
#[Description('Move a campaign through its governed lifecycle.')]
class TransitionCampaignTool extends DomainTransitionTool
{
    /** @param array<string, mixed> $validated */
    protected static function model(array $validated): Model
    {
        return Campaign::query()->with('enterprise')->findOrFail((int) $validated['id']);
    }

    protected static function enterprise(Model $target): Enterprise
    {
        if (! $target instanceof Campaign) {
            throw new \LogicException('Unexpected campaign target.');
        }

        return $target->enterprise;
    }

    protected static function transition(User $actor, DomainResourceService $domain, Model $target, string $status): Model
    {
        if (! $target instanceof Campaign) {
            throw new \LogicException('Unexpected campaign target.');
        }

        return $domain->transitionCampaign($actor, $target, $status);
    }

    protected static function capability(): string
    {
        return 'campaign.lifecycle';
    }

    protected static function operation(): string
    {
        return 'mcp.campaign.lifecycle';
    }
}