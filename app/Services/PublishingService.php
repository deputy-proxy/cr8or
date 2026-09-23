<?php

namespace App\Services;

use App\Contracts\PublishingProvider;
use App\Data\PublishingProviderResult;
use App\Exceptions\PublishingProviderException;
use App\Models\AgentAssignment;
use App\Models\AgentExecution;
use App\Models\ApprovalRequest;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Publication;
use App\Models\PublicationResult;
use App\Models\PublicationSchedule;
use App\Models\PublishingJob;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class PublishingService
{
    public function __construct(private readonly PublishingProvider $provider, private readonly AgentCapabilityAuthorizer $capabilities) {}

    public function schedule(User $actor, ContentItem $content, SocialAccount $account, \DateTimeInterface $at, ?ApprovalRequest $approval = null, ?AgentAssignment $assignment = null, ?AgentExecution $execution = null): Publication
    {
        $this->validateTarget($content, $account);
        $this->authorizePublication($actor, $content, $approval, $assignment, $execution);

        return DB::transaction(function () use ($content, $account, $at, $approval) {
            $p = Publication::query()->create(['enterprise_id' => $content->enterprise_id, 'content_item_id' => $content->id, 'channel_id' => $account->channel_id, 'social_account_id' => $account->id, 'approval_request_id' => $approval?->id, 'status' => Publication::STATUS_SCHEDULED, 'idempotency_key' => 'publication-'.Str::uuid(), 'correlation_id' => app(ExecutionCorrelationService::class)->resolve(), 'scheduled_at' => $at]);
            PublicationSchedule::query()->create(['enterprise_id' => $content->enterprise_id, 'publication_id' => $p->id, 'scheduled_at' => $at, 'status' => PublicationSchedule::STATUS_SCHEDULED]);

            return $p;
        });
    }

    public function submit(User $actor, Publication $p, ?AgentAssignment $assignment = null, ?AgentExecution $execution = null, ?ApprovalRequest $approval = null): Publication
    {
        $p->loadMissing(['contentItem', 'socialAccount.channel', 'channel', 'approvalRequest']);
        /** @var ContentItem $content */
        $content = $p->contentItem;
        /** @var SocialAccount $account */
        $account = $p->socialAccount;
        $this->validateTarget($content, $account);
        $this->authorizePublication($actor, $content, $approval ?? $p->approvalRequest, $assignment, $execution);
        if (in_array($p->status, [Publication::STATUS_SUBMITTED, Publication::STATUS_SUCCEEDED], true)) {
            return $p;
        }$job = PublishingJob::query()->firstOrCreate(['idempotency_key' => 'publish-'.$p->idempotency_key], ['enterprise_id' => $p->enterprise_id, 'publication_id' => $p->id, 'status' => PublishingJob::STATUS_PENDING]);
        if ($job->status === PublishingJob::STATUS_FAILED) {
            $job->status = PublishingJob::STATUS_PENDING;
            $job->failure_code = null;
            $job->failure_reason = null;
            $job->completed_at = null;
        }$job->start()->save();
        try {
            $r = $this->provider->publish(new \App\Data\PublishingRequest($account->external_id, (string) $content->body, $p->scheduled_at?->toIso8601String() ?? now()->toIso8601String(), $p->idempotency_key, ['__type' => $p->channel->type]));
            $p->markSubmitted($r->externalId, $r->externalUrl)->save();
            $job->succeed()->save();
            $this->record($p, $job, $r);

            return $p->refresh();
        } catch (PublishingProviderException $e) {
            $job->fail($e->failureCode, $e->getMessage())->save();
            $p->fail($e->failureCode, $e->getMessage())->save();
            $this->recordFailure($p, $job, $e);
            throw $e;
        }
    }

    public function reconcile(Publication $p, PublishingProviderResult $r): Publication
    {
        if ($p->status === Publication::STATUS_SUCCEEDED) {
            return $p;
        }if ($r->status === 'succeeded') {
            $p->succeed()->save();
        } elseif ($r->status === 'failed') {
            $p->fail('provider_rejected', 'Provider reported publication failure.')->save();
        } else {
            throw new LogicException('Unsupported reconciliation status.');
        }$this->record($p, $p->publishingJobs()->latest('id')->first(), $r);

        return $p->refresh();
    }

    private function validateTarget(ContentItem $c, SocialAccount $a): void
    {
        if ($c->status !== ContentItem::STATUS_PUBLICATION_READY) {
            throw new LogicException('Only publication-ready content can enter the publishing lifecycle.');
        }if ($a->status !== SocialAccount::STATUS_ACTIVE || $a->channel->status !== Channel::STATUS_ACTIVE) {
            throw new LogicException('The social account and channel must both be active.');
        }
    }

    private function authorizePublication(User $actor, ContentItem $c, ?ApprovalRequest $approval, ?AgentAssignment $assignment, ?AgentExecution $execution): void
    {
        if (($assignment === null) !== ($execution === null)) {
            throw new AuthorizationException('Agent publication requires both assignment and execution context.');
        }if ($assignment === null) {
            return;
        }if (! $this->capabilities->allows($assignment, 'publication.publish', $c->enterprise->organization, $c->enterprise, $actor, $approval, $execution, ['content_item_id' => $c->id])) {
            throw new AuthorizationException('The Agent is not authorized to publish this content.');
        }
    }

    private function record(Publication $p, ?PublishingJob $j, PublishingProviderResult $r): PublicationResult
    {
        return PublicationResult::query()->create(['enterprise_id' => $p->enterprise_id, 'publication_id' => $p->id, 'publishing_job_id' => $j?->id, 'provider' => 'postiz', 'provider_status' => $r->status, 'external_id' => $r->externalId, 'external_url' => $r->externalUrl, 'correlation_id' => $p->correlation_id, 'payload' => $r->payload, 'recorded_at' => now()]);
    }

    private function recordFailure(Publication $p, PublishingJob $j, PublishingProviderException $e): PublicationResult
    {
        return PublicationResult::query()->create(['enterprise_id' => $p->enterprise_id, 'publication_id' => $p->id, 'publishing_job_id' => $j->id, 'provider' => 'postiz', 'provider_status' => 'failed', 'correlation_id' => $p->correlation_id, 'failure_code' => $e->failureCode, 'failure_reason' => $e->getMessage(), 'recorded_at' => now()]);
    }
}