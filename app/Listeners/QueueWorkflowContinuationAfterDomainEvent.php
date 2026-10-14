<?php

namespace App\Listeners;

use App\Events\DomainEvent;
use App\Jobs\ProcessWorkflowContinuationOutbox;
use App\Models\WorkflowContinuationOutbox;

final class QueueWorkflowContinuationAfterDomainEvent
{
    public function handle(DomainEvent $event): void
    {
        $outboxId = (int) ($event->data['outbox_id'] ?? 0);

        if ($outboxId < 1) {
            return;
        }

        $pending = WorkflowContinuationOutbox::query()
            ->whereKey($outboxId)
            ->whereNull('processed_at')
            ->exists();

        if ($pending) {
            ProcessWorkflowContinuationOutbox::dispatch($outboxId);
        }
    }
}
