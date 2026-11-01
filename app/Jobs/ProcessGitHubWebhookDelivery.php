<?php

namespace App\Jobs;

use App\Models\GitHubWebhookDelivery;
use App\Services\GitHubWebhookHandler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessGitHubWebhookDelivery implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300, 600];

    public function __construct(public readonly int $deliveryId) {}

    public function uniqueId(): string
    {
        return 'github-webhook-delivery:'.$this->deliveryId;
    }

    public function handle(GitHubWebhookHandler $handler): void
    {
        $delivery = GitHubWebhookDelivery::query()->find($this->deliveryId);
        if ($delivery === null || $delivery->status === 'processed' || $delivery->status === 'ignored') {
            return;
        }
        $handler->handle($delivery);
    }
}
