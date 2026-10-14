<?php

namespace App\Console\Commands;

use App\Jobs\ProcessWorkflowContinuationOutbox;
use App\Models\WorkflowContinuationOutbox;
use Illuminate\Console\Command;

final class DispatchPendingWorkflowContinuations extends Command
{
    protected $signature = 'workflow:dispatch-pending-continuations {--limit=100}';

    protected $description = 'Dispatch durable pending Workflow continuation events from the outbox.';

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $ids = WorkflowContinuationOutbox::query()
            ->whereNull('processed_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            ProcessWorkflowContinuationOutbox::dispatch((int) $id);
        }

        $this->info(sprintf('Dispatched %d pending Workflow continuation event(s).', $ids->count()));

        return self::SUCCESS;
    }
}
