<?php

namespace App\Jobs;

use App\Data\AgentExecutionRequest;
use App\Models\AgentDelegation;
use App\Models\AgentExecution;
use App\Models\User;
use App\Services\AgentExecutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class RunAgentExecutionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [10, 30];

    public int $uniqueFor = 3600;

    public function __construct(
        public int $executionId,
        public int $actorId,
        public ?int $delegationId = null,
        public bool $resume = false,
    ) {
        $this->onQueue('agents');
    }

    public function uniqueId(): string
    {
        return 'agent-execution:'.$this->executionId;
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [new WithoutOverlapping($this->uniqueId())->expireAfter($this->timeout * 2)];
    }

    public function handle(AgentExecutionService $service): void
    {
        $execution = AgentExecution::query()->find($this->executionId);

        if ($execution === null || in_array($execution->status, [
            AgentExecution::STATUS_COMPLETED,
            AgentExecution::STATUS_FAILED,
            AgentExecution::STATUS_CANCELLED,
        ], true)) {
            return;
        }

        if (in_array($execution->status, [
            AgentExecution::STATUS_WAITING_FOR_INPUT,
            AgentExecution::STATUS_WAITING_FOR_APPROVAL,
            AgentExecution::STATUS_DELEGATED,
            AgentExecution::STATUS_PAUSED,
        ], true) && ! $this->resume) {
            return;
        }

        $actor = User::query()->findOrFail($this->actorId);
        $assignment = $execution->agentAssignment()->with(['agentDescriptor', 'organization', 'enterprise'])->firstOrFail();
        $delegation = $this->delegationId === null
            ? null
            : AgentDelegation::query()->findOrFail($this->delegationId);

        $service->execute(new AgentExecutionRequest(
            actor: $actor,
            assignment: $assignment,
            prompt: (string) $execution->prompt,
            targetContext: is_array($execution->target_context) ? $execution->target_context : [],
            expertSlugs: is_array($execution->expert_slugs) ? $execution->expert_slugs : [],
            options: is_array($execution->model_options) ? $execution->model_options : [],
            correlationId: $execution->correlation_id,
            delegation: $delegation,
            idempotencyKey: $execution->idempotency_key,
            allowWorkerRetry: true,
        ));
    }

    public function failed(Throwable $exception): void
    {
        $execution = AgentExecution::query()->find($this->executionId);

        if ($execution === null || in_array($execution->status, [
            AgentExecution::STATUS_COMPLETED,
            AgentExecution::STATUS_FAILED,
            AgentExecution::STATUS_CANCELLED,
        ], true)) {
            return;
        }

        $message = $exception->getMessage() !== '' ? $exception->getMessage() : 'Agent execution worker failed.';
        $isTimeout = str_contains(strtolower($exception::class), 'timeout')
            || str_contains(strtolower($message), 'timeout');

        $execution->failure_code = $isTimeout ? 'timeout' : 'queue_failed';
        $execution->fail($message)->save();
    }
}