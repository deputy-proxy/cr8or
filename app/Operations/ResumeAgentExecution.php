<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Enums\AgentExecutionMode;
use App\Models\AgentExecution;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\AgentExecutionResourceService;
use App\Services\AgentExecutionService;

final class ResumeAgentExecution implements Operation
{
    public function __construct(private readonly AgentExecutionService $executions, private readonly AgentExecutionResourceService $resources) {}

    public function execute(User $actor, array $input): mixed
    {
        $execution = $input['execution'] ?? AgentExecution::query()->findOrFail((int) $input['agent_execution_id']);
        if ($execution->mode === AgentExecutionMode::INTERACTIVE && isset($input['capability_requests'])) {
            $context = is_array($execution->execution_context) ? $execution->execution_context : [];
            $existing = is_array($context['interactive_capability_requests'] ?? null)
                ? $context['interactive_capability_requests']
                : [];
            $incoming = array_values($input['capability_requests']);

            foreach ($incoming as $request) {
                $matched = false;

                foreach ($existing as $index => $stored) {
                    if (! is_array($stored) || ! is_array($request)) {
                        continue;
                    }

                    $sameIdempotency = isset($request['idempotency_key'], $stored['idempotency_key'])
                        && $request['idempotency_key'] === $stored['idempotency_key'];
                    $sameStep = (int) ($request['step'] ?? 1) === (int) ($stored['step'] ?? 1)
                        && ($request['capability'] ?? null) === ($stored['capability'] ?? null);

                    if ($sameIdempotency || $sameStep) {
                        $existing[$index] = array_merge($stored, $request);
                        $matched = true;
                        break;
                    }
                }

                if (! $matched) {
                    $existing[] = $request;
                }
            }

            $context['interactive_capability_requests'] = array_values($existing);
            $execution->execution_context = $context;
            $execution->save();
        }

        $this->executions->queueResume($execution, $actor);
        $enterprise = $input['enterprise'] ?? Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        return $this->resources->get($actor, $enterprise, $execution->refresh());
    }
}