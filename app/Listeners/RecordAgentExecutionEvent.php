<?php

namespace App\Listeners;

use App\Events\AgentExecutionEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class RecordAgentExecutionEvent implements ShouldQueue
{
    use Queueable;

    public function handle(AgentExecutionEvent $event): void
    {
        Log::info('CR8OR Agent execution event.', $event->toArray());
    }
}