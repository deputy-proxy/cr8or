<?php

namespace App\Jobs;

use App\Exceptions\PublishingAdapterException;
use App\Models\Publication;
use App\Services\PublishingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ReconcilePublicationStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $publicationId,
        public readonly int $pollCount = 0,
    ) {}

    public function handle(PublishingService $publishing): void
    {
        $publication = Publication::query()->find($this->publicationId);
        if ($publication === null || in_array($publication->status, [Publication::STATUS_SUCCEEDED, Publication::STATUS_FAILED], true)) {
            return;
        }

        $maxPolls = max(1, min(100, (int) config('publishing.status_reconciliation.max_polls', 48)));
        try {
            $status = $publishing->reconcileStatus($publication);
        } catch (PublishingAdapterException $exception) {
            if (! $exception->retryable || $this->pollCount + 1 >= $maxPolls) {
                throw $exception;
            }
            $this->dispatchNext();

            return;
        }

        if (in_array($status, [null, 'pending'], true) && $this->pollCount + 1 < $maxPolls) {
            $this->dispatchNext();
        }
    }

    private function dispatchNext(): void
    {
        $delay = max(1, min(3600, (int) config('publishing.status_reconciliation.poll_delay_seconds', 300)));
        self::dispatch($this->publicationId, $this->pollCount + 1)
            ->onConnection((string) config('publishing.status_reconciliation.queue_connection', 'database'))
            ->delay(now()->addSeconds($delay));
    }
}