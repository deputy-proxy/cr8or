<?php

namespace App\Jobs;

use App\Models\ApprovalRequest;
use App\Models\ContentItem;
use App\Models\WorkflowContinuationOutbox;
use App\Services\WorkflowExecutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final class ProcessWorkflowContinuationOutbox implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 180, 300];

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $outboxId) {}

    public function uniqueId(): string
    {
        return (string) $this->outboxId;
    }

    public function handle(WorkflowExecutionService $workflows): void
    {
        $event = WorkflowContinuationOutbox::query()->find($this->outboxId);

        if ($event === null || $event->processed_at !== null) {
            return;
        }

        $event->increment('attempts');
        $payloadValue = $event->getAttribute('payload');
        $payload = is_array($payloadValue) ? $payloadValue : (is_string($payloadValue) ? json_decode($payloadValue, true) : []);
        $payload = is_array($payload) ? $payload : [];

        try {
            if ($event->event_type === 'marketing.content.approved') {
                $contentItemId = (int) ($payload['content_item_id'] ?? 0);
                $content = ContentItem::query()
                    ->where('enterprise_id', $event->enterprise_id)
                    ->findOrFail($contentItemId);

                if (! $workflows->continueAfterContentApproval($content, $event->actor_id)) {
                    return;
                }
            } elseif ($event->event_type === 'workflow.approval.granted') {
                $approvalId = (int) ($payload['approval_request_id'] ?? 0);
                $approval = ApprovalRequest::query()
                    ->where('enterprise_id', $event->enterprise_id)
                    ->findOrFail($approvalId);

                if (! $workflows->continueAfterWorkflowApproval($approval)) {
                    return;
                }
            } else {
                throw new LogicException("Unsupported workflow continuation event [{$event->event_type}].");
            }

            $event->forceFill(['processed_at' => now(), 'last_error' => null])->save();
        } catch (Throwable $exception) {
            $event->forceFill(['last_error' => Str::limit($exception->getMessage(), 4000)])->save();

            throw $exception;
        }
    }
}
