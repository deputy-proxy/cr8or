<?php

namespace App\Services;

use App\Events\ContentItemApproved;
use App\Experts\MarketingExpert;
use App\Models\AgentAssignment;
use App\Models\AgentDecision;
use App\Models\AgentExecution;
use App\Models\ApprovalRequest;
use App\Models\Campaign;
use App\Models\ContentItem;
use App\Models\ContentSeries;
use App\Models\Enterprise;
use App\Models\User;
use App\Models\WorkflowContinuationOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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
            'metadata' => $attributes['metadata'] ?? null,
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

        return DB::transaction(function () use ($actor, $item): ContentItem {
            /** @var ContentItem $locked */
            $locked = ContentItem::query()->with('enterprise.organization')->lockForUpdate()->findOrFail($item->getKey());
            Gate::forUser($actor)->authorize('update', $locked);
            $locked->transitionTo(ContentItem::STATUS_APPROVED)->save();
            $locked->refresh()->loadMissing('enterprise.organization');

            $outbox = WorkflowContinuationOutbox::query()->create([
                'event_key' => (string) Str::uuid(),
                'event_type' => ContentItemApproved::EVENT_TYPE,
                'organization_id' => $locked->enterprise->organization_id,
                'enterprise_id' => $locked->enterprise_id,
                'actor_id' => $actor->getKey(),
                'aggregate_id' => $locked->getKey(),
                'payload' => ['content_item_id' => $locked->getKey()],
                'occurred_at' => now(),
                'attempts' => 0,
            ]);

            event(new ContentItemApproved(
                organizationId: (int) $locked->enterprise->organization_id,
                enterpriseId: (int) $locked->enterprise_id,
                actorId: (int) $actor->getKey(),
                correlationId: null,
                data: ['content_item_id' => $locked->getKey(), 'outbox_id' => $outbox->getKey()],
            ));

            return $locked;
        });
    }

    public function markPublicationReady(
        User $actor,
        ContentItem $item,
        ApprovalRequest $approval,
        ?AgentAssignment $assignment = null,
        ?AgentExecution $execution = null,
        ?\App\Models\WorkflowExecution $workflowExecution = null,
    ): ContentItem {
        Gate::forUser($actor)->authorize('update', $item);

        if ($item->status !== ContentItem::STATUS_APPROVED) {
            throw new LogicException('Only approved content can become publication-ready.');
        }

        if ($assignment === null && $execution === null && $workflowExecution !== null) {
            if ((int) $workflowExecution->enterprise_id !== (int) $item->enterprise_id || ! app(ApprovalRequestService::class)->matchesWorkflow($approval, $actor, $workflowExecution, 'marketing.content.publication-ready', ['content_item_id' => $item->getKey()], allowConsumed: true)) {
                throw new \Illuminate\Auth\Access\AuthorizationException('The Workflow publication-readiness approval is invalid or unauthorized.');
            }
            $item->transitionToPublicationReady()->save();

            return $item->refresh();
        }
        if ($assignment === null || $execution === null) {
            throw new LogicException('Publication readiness requires a complete Agent or Workflow approval context.');
        }

        if (! app(AgentCapabilityAuthorizer::class)->allowsExpertCapability(
            $assignment,
            'marketing',
            new MarketingExpert,
            'marketing.content.publication-ready',
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