<?php

namespace App\Services;

use App\Models\AgentAssignment;
use App\Models\AgentDecision;
use App\Models\AgentExecution;
use App\Models\ApprovalRequest;
use App\Models\Campaign;
use App\Models\ContentItem;
use App\Models\ContentSeries;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use LogicException;

final class ContentItemService
{
    /** @param array<string, mixed> $attributes */
    public function create(User $actor, Enterprise $enterprise, array $attributes): ContentItem
    {
        Gate::forUser($actor)->authorize('createForEnterprise', [ContentItem::class, $enterprise]);

        /** @var Campaign $campaign */
        $campaign = Campaign::query()->findOrFail($attributes['campaign_id']);

        if ((int) $campaign->enterprise_id !== (int) $enterprise->getKey()) {
            throw new LogicException('The campaign must belong to the selected enterprise.');
        }

        $seriesId = $attributes['content_series_id'] ?? null;
        if ($seriesId !== null) {
            /** @var ContentSeries $series */
            $series = ContentSeries::query()->findOrFail($seriesId);
            if ((int) $series->campaign_id !== (int) $campaign->getKey()) {
                throw new LogicException('The content series must belong to the selected campaign.');
            }
        }

        return ContentItem::query()->create([
            'enterprise_id' => $enterprise->getKey(),
            'campaign_id' => $campaign->getKey(),
            'content_series_id' => $seriesId,
            'channel_id' => $attributes['channel_id'] ?? null,
            'audience_id' => $attributes['audience_id'] ?? null,
            'title' => $attributes['title'],
            'body' => $attributes['body'] ?? null,
            'status' => ContentItem::STATUS_DRAFT,
            'agent_execution_id' => $attributes['agent_execution_id'] ?? null,
            'agent_decision_id' => $attributes['agent_decision_id'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, ContentItem $item, array $attributes): ContentItem
    {
        Gate::forUser($actor)->authorize('update', $item);

        if (! in_array($item->status, [ContentItem::STATUS_DRAFT, ContentItem::STATUS_IN_REVIEW], true)) {
            throw new LogicException('Only draft or in-review content can be revised.');
        }

        $item->update($attributes);

        return $item->refresh();
    }

    public function submitForReview(User $actor, ContentItem $item): ContentItem
    {
        Gate::forUser($actor)->authorize('update', $item);
        $item->transitionTo(ContentItem::STATUS_IN_REVIEW)->save();

        return $item->refresh();
    }

    public function approve(User $actor, ContentItem $item): ContentItem
    {
        Gate::forUser($actor)->authorize('update', $item);
        $item->transitionTo(ContentItem::STATUS_APPROVED)->save();

        return $item->refresh();
    }

    public function markPublicationReady(
        User $actor,
        ContentItem $item,
        ApprovalRequest $approval,
        ?AgentAssignment $assignment = null,
        ?AgentExecution $execution = null,
    ): ContentItem {
        Gate::forUser($actor)->authorize('update', $item);

        if ($item->status !== ContentItem::STATUS_APPROVED) {
            throw new LogicException('Only approved content can become publication-ready.');
        }

        if ($assignment === null || $execution === null) {
            throw new LogicException('Publication readiness requires an Agent execution approval context.');
        }

        if (! app(AgentCapabilityAuthorizer::class)->allows(
            $assignment,
            'content.publication_ready',
            $item->enterprise->organization,
            $item->enterprise,
            $actor,
            $approval,
            $execution,
            ['content_item_id' => $item->getKey()],
        )) {
            throw new \Illuminate\Auth\Access\AuthorizationException(
                'The content publication-readiness approval is invalid or unauthorized.',
            );
        }

        $item->transitionToPublicationReady()->save();

        return $item->refresh();
    }

    public function attachProvenance(ContentItem $item, ?AgentExecution $execution, ?AgentDecision $decision): ContentItem
    {
        if ($execution !== null && $execution->enterprise_id !== $item->enterprise_id) {
            throw new LogicException('Agent execution provenance must belong to the content enterprise.');
        }

        if ($decision !== null && $decision->enterprise_id !== $item->enterprise_id) {
            throw new LogicException('Agent decision provenance must belong to the content enterprise.');
        }

        $item->agent_execution_id = $execution?->getKey();
        $item->agent_decision_id = $decision?->getKey();
        $item->save();

        return $item;
    }
}
