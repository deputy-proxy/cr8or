<?php

namespace App\Mcp\Tools;

use App\Models\ContentSeries;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\DomainResourceService;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('transition-content-series')]
#[Description('Move a content series through its governed lifecycle.')]
class TransitionContentSeriesTool extends DomainTransitionTool
{
    /** @param array<string, mixed> $validated */
    protected static function model(array $validated): Model
    {
        return ContentSeries::query()->with('campaign.enterprise')->findOrFail((int) $validated['id']);
    }

    protected static function enterprise(Model $target): Enterprise
    {
        if (! $target instanceof ContentSeries) {
            throw new \LogicException('Unexpected content series target.');
        }

        return $target->campaign->enterprise;
    }

    protected static function transition(User $actor, DomainResourceService $domain, Model $target, string $status): Model
    {
        if (! $target instanceof ContentSeries) {
            throw new \LogicException('Unexpected content series target.');
        }

        return $domain->transitionContentSeries($actor, $target, $status);
    }

    protected static function capability(): string
    {
        return 'content_series.lifecycle';
    }

    protected static function operation(): string
    {
        return 'mcp.content-series.lifecycle';
    }
}
