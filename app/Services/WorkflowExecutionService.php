<?php

namespace App\Services;

use App\Data\CapabilityInvocationRequest;
use App\Data\ResolvedWorkflowStageInput;
use App\Models\ApprovalRequest;
use App\Models\ContentItem;
use App\Models\Enterprise;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\WorkflowStage;
use App\Models\WorkflowVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

final class WorkflowExecutionService
{
    public function __construct(
        private readonly CapabilityInvocationService $capabilities,
        private readonly WorkflowStageInputResolver $inputResolver,
        private readonly WorkflowStageOutputValidator $outputValidator,
    ) {}

    /** @param array<string, mixed> $input */
    public function start(User $actor, Workflow|WorkflowVersion $definition, array $input, string $idempotencyKey, ?string $correlationId = null, bool $returnFailed = false, ?Enterprise $enterprise = null): WorkflowExecution
    {
        $version = $definition instanceof WorkflowVersion ? $definition : $definition->publishedVersion;

        if (! $version instanceof WorkflowVersion || $version->status !== WorkflowVersion::STATUS_PUBLISHED) {
            throw new AuthorizationException('Deterministic Workflow execution requires a published WorkflowVersion.');
        }

        $workflow = $version->workflow;
        $enterprise ??= $workflow->enterprise;

        if (! $enterprise instanceof Enterprise) {
            throw new AuthorizationException('Generic Workflow execution requires an enterprise context.');
        }

        Gate::forUser($actor)->authorize('viewForEnterprise', [$workflow, $enterprise]);

        if (
            $version->enterprise_id !== null
            && (int) $version->enterprise_id !== (int) $enterprise->getKey()
        ) {
            throw new AuthorizationException('WorkflowVersion is not available for the execution Enterprise.');
        }

        $existing = WorkflowExecution::query()
            ->where('workflow_version_id', $version->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $this->validateExecutionInput($version, $input);

        $execution = DB::transaction(function () use ($actor, $workflow, $version, $enterprise, $input, $idempotencyKey, $correlationId): WorkflowExecution {
            return WorkflowExecution::query()->create([
                'workflow_id' => $workflow->getKey(),
                'workflow_version_id' => $version->getKey(),
                'workflow_version' => $version->version,
                'enterprise_id' => $enterprise->getKey(),
                'actor_id' => $actor->getKey(),
                'status' => WorkflowExecution::STATUS_PENDING,
                'correlation_id' => $correlationId ?? str()->uuid()->toString(),
                'idempotency_key' => $idempotencyKey,
                'continuation_token' => (string) str()->uuid(),
                'input' => $input,
                'outputs' => [],
                'context' => $this->initialExecutionContext($input, $version),
            ]);
        });

        return $this->continue($actor, $execution, null, $returnFailed);
    }

    /** @return array<string, mixed> */
    public function inspect(User $actor, WorkflowExecution $execution): array
    {
        $execution->loadMissing(['workflow.enterprise', 'workflowVersion']);
        Gate::forUser($actor)->authorize('view', $execution->workflow);

        $outputsValue = $execution->getAttribute('outputs');
        $outputs = is_array($outputsValue) ? $outputsValue : [];
        $next = $this->nextStage($execution);

        return [
            'id' => $execution->getKey(),
            'workflow_id' => $execution->workflow_id,
            'workflow_version_id' => $execution->workflow_version_id,
            'workflow_version' => $execution->workflow_version,
            'status' => $execution->status,
            'current_stage_key' => $execution->current_stage_key,
            'completed_stage_keys' => array_keys($outputs),
            'waiting' => in_array($execution->status, [
                WorkflowExecution::STATUS_WAITING_FOR_INPUT,
                WorkflowExecution::STATUS_WAITING_FOR_APPROVAL,
            ], true),
            'state_reason' => $execution->state_reason,
            'failure_reason' => $execution->failure_reason,
            'outputs' => $outputs,
            'next_stage_key' => $next?->key,
            'correlation_id' => $execution->correlation_id,
            'idempotency_key' => $execution->idempotency_key,
            'continuation_token' => $execution->continuation_token,
        ];
    }

    /** @param array<string, mixed> $input */
    public function continue(User $actor, WorkflowExecution $execution, ?string $continuationToken = null, bool $returnFailed = false, array $input = []): WorkflowExecution
    {
        $repeatableStagesToReenter = [];
        $claimed = DB::transaction(function () use ($actor, $execution, $continuationToken, $input, &$repeatableStagesToReenter): WorkflowExecution {
            /** @var WorkflowExecution $locked */
            $locked = WorkflowExecution::query()->lockForUpdate()->whereKey($execution->getKey())->firstOrFail();

            if ($continuationToken !== null && ! hash_equals((string) $locked->continuation_token, $continuationToken)) {
                throw new AuthorizationException('Workflow continuation token is stale or invalid.');
            }

            $locked->loadMissing(['workflow.enterprise', 'workflowVersion']);

            if (! $locked->workflowVersion instanceof WorkflowVersion || $locked->workflowVersion->status === WorkflowVersion::STATUS_DRAFT) {
                throw new AuthorizationException('Workflow execution must reference a published WorkflowVersion.');
            }

            Gate::forUser($actor)->authorize('view', $locked->workflow);

            if ($locked->status === WorkflowExecution::STATUS_COMPLETED) {
                return $locked;
            }

            if ($input !== []) {
                $this->validateExecutionInput($locked->workflowVersion, $input);
                $currentInput = $locked->getAttribute('input');
                $currentInput = is_array($currentInput) ? $currentInput : [];
                $mergedInput = $currentInput;

                if (isset($input['workflow']) && is_array($input['workflow'])) {
                    $mergedInput['workflow'] = array_replace(
                        is_array($mergedInput['workflow'] ?? null) ? $mergedInput['workflow'] : [],
                        $input['workflow'],
                    );
                }

                if (isset($input['stages']) && is_array($input['stages'])) {
                    $mergedInput['stages'] = is_array($mergedInput['stages'] ?? null) ? $mergedInput['stages'] : [];
                    foreach ($input['stages'] as $stageKey => $stageInput) {
                        $mergedInput['stages'][$stageKey] = array_replace(
                            is_array($mergedInput['stages'][$stageKey] ?? null) ? $mergedInput['stages'][$stageKey] : [],
                            is_array($stageInput) ? $stageInput : [],
                        );
                    }
                }

                foreach ($input as $key => $value) {
                    if ($key !== 'workflow' && $key !== 'stages') {
                        $mergedInput[$key] = $value;
                    }
                }

                $locked->setAttribute('input', $mergedInput);
                $locked->save();
            }

            if ($locked->status === WorkflowExecution::STATUS_RUNNING) {
                throw new LogicException('Workflow execution is already running.');
            }

            if ($locked->status === WorkflowExecution::STATUS_FAILED) {
                $outputsValue = $locked->getAttribute('outputs');
                $outputs = is_array($outputsValue) ? $outputsValue : [];
                $definitionsValue = $locked->workflowVersion->getAttribute('stage_definitions');
                $definitions = is_array($definitionsValue) ? $definitionsValue : [];

                foreach ($definitions as $definition) {
                    if (! is_array($definition) || ! is_string($definition['key'] ?? null)) {
                        continue;
                    }

                    if (($definition['repeatable'] ?? false) && array_key_exists($definition['key'], $outputs)) {
                        $repeatableStagesToReenter[] = $definition['key'];
                    }
                }

                $locked->retry();
            } else {
                $locked->start();
            }

            // Rotate the token before releasing the lock so another caller cannot
            // claim the same continuation while this execution is running.
            $locked->continuation_token = (string) str()->uuid();
            $locked->save();

            return $locked->refresh();
        });

        if ($claimed->status === WorkflowExecution::STATUS_COMPLETED) {
            return $claimed;
        }

        return $this->runStages($actor, $claimed, $returnFailed, $repeatableStagesToReenter);
    }

    /**
     * Continue eligible failed or approval-waiting WorkflowExecutions after an authorized
     * content approval. The approval transition is authoritative evidence that review and
     * content approval have already occurred; those non-repeatable stages are reconciled
     * against the immutable WorkflowVersion rather than executed again.
     */
    public function continueAfterContentApproval(ContentItem $item, ?int $approverId = null): bool
    {
        if (! in_array($item->status, [ContentItem::STATUS_APPROVED, ContentItem::STATUS_PUBLICATION_READY], true)) {
            return true;
        }

        $candidates = WorkflowExecution::query()
            ->where('enterprise_id', $item->enterprise_id)
            ->whereIn('status', [WorkflowExecution::STATUS_RUNNING, WorkflowExecution::STATUS_FAILED, WorkflowExecution::STATUS_WAITING_FOR_APPROVAL])
            ->with(['workflow.enterprise', 'workflowVersion', 'actor', 'enterprise'])
            ->orderBy('id')
            ->get();

        foreach ($candidates as $execution) {
            if (! $this->canReconcileApprovedContent($execution, $item)) {
                continue;
            }

            if ($execution->status === WorkflowExecution::STATUS_RUNNING) {
                return false;
            }

            $actor = $execution->actor;
            if (! $actor instanceof User) {
                continue;
            }

            $this->recoverApprovedContent($actor, $execution, $item, $approverId);
        }

        return true;
    }

    public function continueAfterWorkflowApproval(ApprovalRequest $approval): bool
    {
        if ($approval->status !== ApprovalRequest::STATUS_APPROVED || $approval->workflow_execution_id === null) {
            return true;
        }

        $execution = WorkflowExecution::query()
            ->with(['workflow.enterprise', 'workflowVersion', 'actor', 'enterprise'])
            ->find($approval->workflow_execution_id);
        if (! $execution instanceof WorkflowExecution) {
            return true;
        }

        if ($execution->status === WorkflowExecution::STATUS_RUNNING) {
            return false;
        }
        if ($execution->status !== WorkflowExecution::STATUS_WAITING_FOR_APPROVAL) {
            return true;
        }

        $context = $execution->getAttribute('context');
        $context = is_array($context) ? $context : [];
        if ((int) ($context['pending_approval_request_id'] ?? 0) !== (int) $approval->getKey()) {
            return true;
        }

        $actor = $execution->actor;
        if (! $actor instanceof User) {
            throw new LogicException('Workflow approval continuation requires the original execution actor.');
        }
        Gate::forUser($actor)->authorize('viewForEnterprise', [$execution->workflow, $execution->enterprise]);

        $definitions = $execution->workflowVersion?->getAttribute('stage_definitions');
        $currentDefinition = null;
        foreach (is_array($definitions) ? $definitions : [] as $definition) {
            if (is_array($definition) && ($definition['key'] ?? null) === $execution->current_stage_key) {
                $currentDefinition = $definition;
                break;
            }
        }
        $stageCapabilities = is_array($currentDefinition['capability_slugs'] ?? null) ? $currentDefinition['capability_slugs'] : [];
        $target = $approval->getAttribute('target_context');
        $target = is_array($target) ? $target : [];
        $expectedContentId = (int) data_get($execution->outputs, 'create_content.id', 0);
        if ($expectedContentId < 1) {
            $executionInput = $execution->getAttribute('input');
            $executionInput = is_array($executionInput) ? $executionInput : [];
            $expectedContentId = (int) data_get(
                $executionInput,
                'stages.'.$execution->current_stage_key.'.content_item_id',
                data_get($executionInput, 'content_item_id', 0),
            );
        }
        $targetContentId = (int) ($target['content_item_id'] ?? 0);
        if ((int) $approval->enterprise_id !== (int) $execution->enterprise_id
            || (int) $approval->actor_id !== (int) $execution->actor_id
            || (int) $approval->workflow_execution_id !== (int) $execution->getKey()
            || $approval->consumed_workflow_execution_id !== null
            || ! in_array($approval->capability, $stageCapabilities, true)
            || (in_array($approval->capability, ['marketing.content.approve', 'marketing.content.publication-ready'], true)
                && ($expectedContentId < 1 || $targetContentId !== $expectedContentId))) {
            throw new AuthorizationException('Approved request is not valid for the waiting WorkflowExecution.');
        }

        $this->continue($actor, $execution);

        return true;
    }

    private function canReconcileApprovedContent(WorkflowExecution $execution, ContentItem $item): bool
    {
        if ((int) $execution->enterprise_id !== (int) $item->enterprise_id
            || ! in_array($execution->status, [WorkflowExecution::STATUS_RUNNING, WorkflowExecution::STATUS_FAILED, WorkflowExecution::STATUS_WAITING_FOR_APPROVAL], true)) {
            return false;
        }

        $outputs = $execution->getAttribute('outputs');
        $outputs = is_array($outputs) ? $outputs : [];
        if ((int) data_get($outputs, 'create_content.id', 0) !== (int) $item->getKey()) {
            return false;
        }

        $definitions = $execution->workflowVersion?->getAttribute('stage_definitions');
        if (! is_array($definitions)) {
            return false;
        }

        $reviewKey = null;
        $approvalKey = null;
        foreach ($definitions as $definition) {
            if (! is_array($definition) || ! is_string($definition['key'] ?? null)) {
                continue;
            }
            $capabilities = $definition['capability_slugs'] ?? [];
            if (in_array('marketing.content.review', $capabilities, true)) {
                $reviewKey = $definition['key'];
            }
            if (in_array('marketing.content.approve', $capabilities, true)) {
                $approvalKey = $definition['key'];
            }
        }

        return $reviewKey !== null
            && $approvalKey !== null
            && in_array($execution->current_stage_key, [$reviewKey, $approvalKey], true);
    }

    private function recoverApprovedContent(User $actor, WorkflowExecution $execution, ContentItem $item, ?int $approverId = null): WorkflowExecution
    {
        [$claimed, $shouldContinue] = DB::transaction(function () use ($actor, $execution, $item, $approverId): array {
            /** @var WorkflowExecution $locked */
            $locked = WorkflowExecution::query()->lockForUpdate()->whereKey($execution->getKey())->firstOrFail();

            if (! in_array($locked->status, [WorkflowExecution::STATUS_FAILED, WorkflowExecution::STATUS_WAITING_FOR_APPROVAL], true)) {
                return [$locked, false];
            }

            $locked->loadMissing(['workflow.enterprise', 'workflowVersion', 'actor']);
            Gate::forUser($actor)->authorize('viewForEnterprise', [$locked->workflow, $locked->enterprise]);

            if ((int) $locked->actor_id !== (int) $actor->getKey()
                || (int) $locked->enterprise_id !== (int) $item->enterprise_id
                || ! in_array($item->status, [ContentItem::STATUS_APPROVED, ContentItem::STATUS_PUBLICATION_READY], true)) {
                throw new AuthorizationException('Approved content cannot recover this WorkflowExecution.');
            }

            $outputsValue = $locked->getAttribute('outputs');
            $outputs = is_array($outputsValue) ? $outputsValue : [];
            if ((int) data_get($outputs, 'create_content.id', 0) !== (int) $item->getKey()) {
                throw new AuthorizationException('Approved content does not match this WorkflowExecution target.');
            }

            $version = $locked->workflowVersion;
            $definitions = $version?->getAttribute('stage_definitions');
            if (! is_array($definitions)) {
                throw new LogicException('Workflow recovery requires the persisted WorkflowVersion stage snapshot.');
            }

            $stages = [];
            foreach ($definitions as $definition) {
                if (! is_array($definition) || ! is_string($definition['key'] ?? null)) {
                    continue;
                }
                $capabilities = $definition['capability_slugs'] ?? [];
                if (in_array('marketing.content.review', $capabilities, true) || in_array('marketing.content.approve', $capabilities, true)) {
                    if (($definition['repeatable'] ?? false) === true) {
                        throw new LogicException('Workflow recovery cannot reconcile repeatable content lifecycle stages.');
                    }
                    $stages[$definition['key']] = $definition;
                }
            }

            $currentKey = (string) $locked->current_stage_key;
            if (! isset($stages[$currentKey])) {
                return [$locked, false];
            }

            $reviewKeys = [];
            $approvalKeys = [];
            foreach ($stages as $key => $definition) {
                $capabilities = is_array($definition['capability_slugs'] ?? null) ? $definition['capability_slugs'] : [];
                if (in_array('marketing.content.review', $capabilities, true)) {
                    $reviewKeys[] = $key;
                }
                if (in_array('marketing.content.approve', $capabilities, true)) {
                    $approvalKeys[] = $key;
                }
            }
            if (count($reviewKeys) !== 1 || count($approvalKeys) !== 1) {
                throw new LogicException('Workflow recovery requires exactly one review stage and one content-approval stage.');
            }

            $reconcileKeys = [$reviewKeys[0], $approvalKeys[0]];
            $available = array_fill_keys(array_keys($outputs), true);
            foreach ($reconcileKeys as $key) {
                $definition = $stages[$key];
                foreach (is_array($definition['dependencies'] ?? null) ? $definition['dependencies'] : [] as $dependency) {
                    if (! is_string($dependency) || (! isset($available[$dependency]) && ! in_array($dependency, $reconcileKeys, true))) {
                        throw new LogicException("Workflow stage [{$key}] has an unsatisfied dependency and cannot be reconciled.");
                    }
                }
            }

            usort($reconcileKeys, static fn (string $left, string $right): int => (int) ($stages[$left]['sequence'] ?? 0) <=> (int) ($stages[$right]['sequence'] ?? 0));
            $reconciledStageKeys = [];
            foreach ($reconcileKeys as $key) {
                if (array_key_exists($key, $outputs)) {
                    continue;
                }

                $definition = $stages[$key];
                $capabilities = is_array($definition['capability_slugs'] ?? null) ? $definition['capability_slugs'] : [];
                $capability = $capabilities[0] ?? null;
                if (! in_array($capability, ['marketing.content.review', 'marketing.content.approve'], true)) {
                    throw new LogicException("Workflow stage [{$key}] is not a reconcilable content lifecycle stage.");
                }

                $stageOutput = [
                    'success' => true,
                    'result' => $item->toArray(),
                    'id' => $item->getKey(),
                    'status' => $item->status,
                    'termination' => 'completed',
                    'reconciled' => true,
                ];
                $stage = new WorkflowStage;
                $stage->forceFill([
                    'key' => $key,
                    'output_contract' => is_array($definition['output_contract'] ?? null) ? $definition['output_contract'] : [],
                    'completion_criteria' => is_array($definition['completion_criteria'] ?? null) ? $definition['completion_criteria'] : [],
                ]);
                $this->outputValidator->validate($stage, $stageOutput);
                $this->validateCapabilityOutputContract($stage, $stageOutput);
                if (! $stage->completionSatisfied($stageOutput)) {
                    throw new LogicException("Workflow stage [{$key}] recovery output does not satisfy its completion criteria.");
                }

                $outputs[$key] = $stageOutput;
                $reconciledStageKeys[] = $key;
            }

            $contextValue = $locked->getAttribute('context');
            $context = is_array($contextValue) ? $contextValue : [];
            $pendingApprovalId = (int) ($context['pending_approval_request_id'] ?? 0);
            if ($pendingApprovalId > 0) {
                $pendingApproval = ApprovalRequest::query()->find($pendingApprovalId);
                if ($pendingApproval instanceof ApprovalRequest && $pendingApproval->status === ApprovalRequest::STATUS_PENDING) {
                    $pendingTarget = $pendingApproval->getAttribute('target_context');
                    $pendingTarget = is_array($pendingTarget) ? $pendingTarget : [];
                    if ((int) $pendingApproval->workflow_execution_id !== (int) $locked->getKey()
                        || (int) $pendingApproval->actor_id !== (int) $locked->actor_id
                        || $pendingApproval->capability !== 'marketing.content.approve'
                        || (int) ($pendingTarget['content_item_id'] ?? 0) !== (int) $item->getKey()) {
                        throw new AuthorizationException('Pending content approval does not match the approved recovery target.');
                    }

                    app(ApprovalRequestService::class)->cancel(
                        $pendingApproval,
                        $actor,
                        'Superseded by an authorized content approval transition.',
                    );
                }
            }

            if ($locked->status === WorkflowExecution::STATUS_FAILED) {
                $locked->retry();
            } else {
                $locked->start();
            }

            $contextValue = $locked->getAttribute('context');
            $context = is_array($contextValue) ? $contextValue : [];
            $contextStages = is_array($context['stages'] ?? null) ? $context['stages'] : [];
            foreach ($reconcileKeys as $key) {
                if (isset($outputs[$key]['reconciled'])) {
                    $contextStages[$key] = $outputs[$key];
                }
            }
            $context['stages'] = $contextStages;
            $stageReconciliations = is_array($context['stage_reconciliations'] ?? null) ? $context['stage_reconciliations'] : [];
            $reconciledAt = now()->toISOString();
            foreach ($reconciledStageKeys as $reconciledKey) {
                $stageReconciliations[$reconciledKey] = [
                    'reason' => 'content_approved_domain_state',
                    'content_item_id' => $item->getKey(),
                    'approval_actor_id' => $approverId,
                    'reconciled_at' => $reconciledAt,
                ];
            }
            $context['stage_reconciliations'] = $stageReconciliations;
            unset($context['pending_approval_request_id']);
            $locked->setAttribute('outputs', $outputs);
            $locked->setAttribute('context', $context);
            $locked->current_stage_key = null;
            $locked->current_stage_id = null;
            $locked->state_reason = null;
            $locked->continuation_token = (string) str()->uuid();
            $locked->save();

            return [$locked->refresh(), true];
        });

        return $shouldContinue ? $this->runStages($actor, $claimed) : $claimed;
    }

    /** @param list<string> $repeatableStagesToReenter */
    private function runStages(User $actor, WorkflowExecution $execution, bool $returnFailed = false, array $repeatableStagesToReenter = []): WorkflowExecution
    {
        try {
            while (true) {
                $stage = DB::transaction(function () use ($execution, $repeatableStagesToReenter): ?WorkflowStage {
                    /** @var WorkflowExecution $locked */
                    $locked = WorkflowExecution::query()->lockForUpdate()->whereKey($execution->getKey())->firstOrFail();

                    if ($locked->status !== WorkflowExecution::STATUS_RUNNING) {
                        return null;
                    }

                    $stage = $this->nextStage($locked, $repeatableStagesToReenter);

                    if ($stage === null) {
                        $locked->complete()->save();

                        return null;
                    }

                    if (in_array($stage->key, $repeatableStagesToReenter, true)) {
                        $contextValue = $locked->getAttribute('context');
                        $context = is_array($contextValue) ? $contextValue : [];
                        $attempts = is_array($context['stage_attempts'] ?? null) ? $context['stage_attempts'] : [];
                        $attempts[$stage->key] = max(1, (int) ($attempts[$stage->key] ?? 1)) + 1;
                        $context['stage_attempts'] = $attempts;
                        $locked->setAttribute('context', $context);
                    }

                    $locked->current_stage_key = $stage->key;
                    $locked->state_reason = null;
                    $locked->save();

                    return $stage;
                });

                if ($stage === null) {
                    return $execution->refresh();
                }

                $repeatableStagesToReenter = array_values(array_diff($repeatableStagesToReenter, [$stage->key]));

                // The capability is deliberately invoked outside any transaction and
                // therefore outside the WorkflowExecution row lock.
                $result = $this->executeStage($actor, $execution->refresh(), $stage);
                $stageOutput = null;

                if (! in_array($result['status'], ['waiting', 'waiting_for_input'], true)) {
                    $stageOutput = is_array($result['result'] ?? null)
                        ? $result['result']
                        : ['value' => $result['result'] ?? null];
                    $stageOutput = $this->adaptStageOutput($stage, $stageOutput);
                    $this->outputValidator->validate($stage, $stageOutput);
                    $this->validateCapabilityOutputContract($stage, $stageOutput);
                }

                $state = DB::transaction(function () use ($execution, $stage, $result, $stageOutput): array {
                    /** @var WorkflowExecution $locked */
                    $locked = WorkflowExecution::query()->lockForUpdate()->whereKey($execution->getKey())->firstOrFail();

                    if ($locked->status !== WorkflowExecution::STATUS_RUNNING || $locked->current_stage_key !== $stage->key) {
                        throw new LogicException("Workflow execution changed while stage [{$stage->key}] was executing.");
                    }

                    if ($result['status'] === 'waiting') {
                        $contextValue = $locked->getAttribute('context');
                        /** @var array<string, mixed> $context */
                        $context = is_array($contextValue) ? $contextValue : [];
                        $context['pending_approval_request_id'] = $result['approval']->getKey();
                        $locked->setAttribute('context', $context);
                        $locked->continuation_token = (string) str()->uuid();
                        $locked->waitForApproval('Workflow stage is waiting for approval.')->save();

                        return ['waiting' => true];
                    }

                    if ($result['status'] === 'waiting_for_input') {
                        $locked->continuation_token = (string) str()->uuid();
                        $locked->waitForInput($result['reason'])->save();

                        return ['waiting' => true];
                    }

                    $resultValue = $result['result'];
                    $stageOutput ??= is_array($resultValue) ? $resultValue : ['value' => $resultValue];
                    $stageOutput['termination'] = 'completed';

                    if (! $stage->completionSatisfied($stageOutput, [$result])) {
                        throw new AuthorizationException("Workflow stage [{$stage->key}] did not satisfy its completion criteria.");
                    }

                    $outputsValue = $locked->getAttribute('outputs');
                    /** @var array<string, mixed> $outputs */
                    $outputs = is_array($outputsValue) ? $outputsValue : [];
                    $outputs[$stage->key] = $stageOutput;
                    $locked->setAttribute('outputs', $outputs);
                    $locked->continuation_token = (string) str()->uuid();

                    $contextValue = $locked->getAttribute('context');
                    /** @var array<string, mixed> $context */
                    $context = is_array($contextValue) ? $contextValue : [];
                    $context['stages'][$stage->key] = $stageOutput;
                    unset($context['pending_approval_request_id']);
                    $locked->setAttribute('context', $context);
                    $locked->save();

                    return ['waiting' => false];
                });

                if ($state['waiting']) {
                    return $execution->refresh();
                }
            }
        } catch (Throwable $exception) {
            DB::transaction(function () use ($execution, $exception): void {
                /** @var WorkflowExecution $locked */
                $locked = WorkflowExecution::query()->lockForUpdate()->whereKey($execution->getKey())->firstOrFail();

                if ($locked->status !== WorkflowExecution::STATUS_COMPLETED) {
                    $locked->continuation_token = (string) str()->uuid();
                    $locked->fail($exception->getMessage())->save();
                }
            });

            if ($returnFailed) {
                return $execution->refresh();
            }

            throw $exception;
        }
    }

    /** @return array{status: string, result?: mixed, approval?: ApprovalRequest, reason?: string} */
    /** @param array<string, mixed> $output
     * @return array<string, mixed>
     */
    private function adaptStageOutput(WorkflowStage $stage, array $output): array
    {
        $contract = $stage->getAttribute('capability_output_contract');
        if (! is_array($contract)
            || ! array_key_exists('success', $contract)
            || ! array_key_exists('result', $contract)) {
            return $output;
        }

        // Operations may return their domain result directly while the public
        // Capability contract exposes the standard success/result envelope. Keep
        // the domain fields at the top level for existing deterministic mappings.
        if (! array_key_exists('success', $output)) {
            return ['success' => true, 'result' => $output] + $output;
        }

        $domainResult = $output['result'] ?? null;

        return is_array($domainResult) ? $output + $domainResult : $output;
    }

    /** @param array<string, mixed> $output */
    private function validateCapabilityOutputContract(WorkflowStage $stage, array $output): void
    {
        $contract = $stage->getAttribute('capability_output_contract');
        if (! is_array($contract) || $contract === []) {
            return;
        }

        $contractStage = new WorkflowStage;
        $contractStage->forceFill([
            'key' => $stage->key,
            'output_contract' => $contract,
        ]);
        $this->outputValidator->validate($contractStage, $output);
    }

    /** @return array{status: 'executed', capability: string, result: mixed, raw_result?: mixed, provenance: array<string, mixed>}|array{status: 'waiting', capability: string, approval: ApprovalRequest, provenance: array<string, mixed>}|array{status: 'waiting_for_input', reason: string} */
    private function executeStage(User $actor, WorkflowExecution $execution, WorkflowStage $stage): array
    {
        $expertSlugsValue = $stage->getAttribute('expert_slugs');
        $capabilitySlugsValue = $stage->getAttribute('capability_slugs');
        $expertSlugs = is_array($expertSlugsValue) ? $expertSlugsValue : [];
        $capabilitySlugs = is_array($capabilitySlugsValue) ? $capabilitySlugsValue : [];
        $expertSlug = $expertSlugs[0] ?? null;
        $capability = $capabilitySlugs[0] ?? null;

        if ($expertSlug === null || $capability === null) {
            throw new AuthorizationException("Workflow stage [{$stage->key}] must declare an Expert and Capability.");
        }

        $contextValue = $execution->getAttribute('context');
        /** @var array<string, mixed> $context */
        $context = is_array($contextValue) ? $contextValue : [];
        $approval = isset($context['pending_approval_request_id'])
            ? ApprovalRequest::query()->find((int) $context['pending_approval_request_id'])
            : null;

        $inputValue = $execution->getAttribute('input');
        /** @var array<string, mixed> $executionInput */
        $executionInput = is_array($inputValue) ? $inputValue : [];
        $version = $execution->workflowVersion()->firstOrFail();
        $resolved = $this->inputResolver->resolve($execution, $version, $stage, $executionInput);

        if ($resolved->requested !== []) {
            $this->persistInputResolution($execution, $stage, $resolved);

            return [
                'status' => 'waiting_for_input',
                'reason' => 'Workflow stage requires additional input: '.implode(', ', $resolved->requested).'.',
            ];
        }

        $inputPayload = $resolved->inputs;
        $this->persistInputResolution($execution, $stage, $resolved);

        $request = new CapabilityInvocationRequest(
            capability: $capability,
            actor: $actor,
            enterprise: $execution->enterprise,
            targetContext: [
                'workflow_execution_id' => $execution->getKey(),
                'workflow_version_id' => $execution->workflow_version_id,
                'workflow_stage_key' => $stage->key,
                'workflow_stage_instruction' => $stage->instruction,
                ...$context,
            ],
            inputPayload: $inputPayload,
            correlationId: $execution->correlation_id,
            idempotencyKey: $this->stageIdempotencyKey($execution, $stage),
            expertSlug: $expertSlug,
            approval: $approval,
            workflowExecution: $execution,
            workflowStage: $stage,
        );

        $invocation = $this->capabilities->invoke($request);
        if ($invocation['status'] === 'waiting') {
            $approvalRequest = $invocation['approval'] ?? null;
            if (! $approvalRequest instanceof ApprovalRequest) {
                throw new LogicException('An approval-waiting Capability invocation must return its ApprovalRequest.');
            }

            return [
                'status' => 'waiting',
                'capability' => $invocation['capability'],
                'approval' => $approvalRequest,
                'provenance' => $invocation['provenance'],
            ];
        }

        return [
            'status' => 'executed',
            'capability' => $invocation['capability'],
            'result' => $invocation['result'] ?? null,
            'raw_result' => $invocation['raw_result'] ?? null,
            'provenance' => $invocation['provenance'],
        ];
    }

    private function persistInputResolution(WorkflowExecution $execution, WorkflowStage $stage, ResolvedWorkflowStageInput $resolved): void
    {
        $contextValue = $execution->getAttribute('context');
        /** @var array<string, mixed> $context */
        $context = is_array($contextValue) ? $contextValue : [];
        $resolutions = is_array($context['stage_input_resolutions'] ?? null)
            ? $context['stage_input_resolutions']
            : [];
        $resolutions[$stage->key] = $resolved->provenance();
        $context['stage_input_resolutions'] = $resolutions;
        $execution->setAttribute('context', $context);
        $execution->save();
    }

    /**
     * Build the durable execution context from workflow-level input.
     *
     * Structured input keeps workflow-level values under `workflow` so they cannot
     * overwrite stage input when the Capability request target context is assembled.
     * Legacy flat input preserves the historical context shape for existing workflows.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function initialExecutionContext(array $input, WorkflowVersion $version): array
    {
        if (! array_key_exists('stages', $input)) {
            return [
                ...$input,
                'workflow_version_id' => $version->getKey(),
                'workflow_version' => $version->version,
            ];
        }

        $workflowInput = is_array($input['workflow'] ?? null) ? $input['workflow'] : [];

        return [
            'workflow' => $workflowInput,
            'workflow_version_id' => $version->getKey(),
            'workflow_version' => $version->version,
        ];
    }

    /**
     * Validate the top-level structure of a structured Workflow execution input.
     *
     * @param  array<string, mixed>  $input
     */
    private function validateExecutionInput(WorkflowVersion $version, array $input): void
    {
        if (! array_key_exists('stages', $input)) {
            return;
        }

        if (! is_array($input['stages'])) {
            throw ValidationException::withMessages([
                'workflow.stages' => 'Workflow execution input [stages] must be an object keyed by Workflow stage key.',
            ]);
        }

        $definitions = $version->getAttribute('stage_definitions');
        $stageDefinitions = is_array($definitions) ? $definitions : [];
        $knownStages = [];
        $allowedInputs = [];

        foreach ($stageDefinitions as $definition) {
            if (! is_array($definition) || ! is_string($definition['key'] ?? null)) {
                continue;
            }

            $key = $definition['key'];
            $knownStages[$key] = true;
            $contract = $definition['capability_input_contract'] ?? [];
            $allowedInputs[$key] = is_array($contract) ? array_keys($contract) : [];
        }

        $errors = [];

        foreach ($input['stages'] as $stageKey => $stageInput) {
            if (! is_string($stageKey) || ! isset($knownStages[$stageKey])) {
                $errors["workflow.stages.{$stageKey}"] = "Workflow execution input references unknown stage [{$stageKey}].";

                continue;
            }

            if (! is_array($stageInput)) {
                $errors["workflow.{$stageKey}"] = "Workflow stage input [{$stageKey}] must be an object.";

                continue;
            }

            $allowed = $allowedInputs[$stageKey] ?? [];

            if ($allowed === []) {
                continue;
            }

            foreach (array_keys($stageInput) as $inputKey) {
                if (is_string($inputKey) && ! in_array($inputKey, $allowed, true)) {
                    $errors["workflow.{$stageKey}.{$inputKey}"] = "Workflow stage [{$stageKey}] does not declare input [{$inputKey}].";
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function stageIdempotencyKey(WorkflowExecution $execution, WorkflowStage $stage): string
    {
        $contextValue = $execution->getAttribute('context');
        $context = is_array($contextValue) ? $contextValue : [];
        $attempts = is_array($context['stage_attempts'] ?? null) ? $context['stage_attempts'] : [];
        $attempt = max(1, (int) ($attempts[$stage->key] ?? 1));
        $key = $execution->idempotency_key.':'.$stage->key;

        return $attempt > 1 ? $key.':repeat-'.$attempt : $key;
    }

    /** @param list<string> $repeatableStagesToReenter */
    private function nextStage(WorkflowExecution $execution, array $repeatableStagesToReenter = []): ?WorkflowStage
    {
        $definitionsRaw = $execution->workflowVersion?->getRawOriginal('stage_definitions');
        if (is_array($definitionsRaw)) {
            $definitions = $definitionsRaw;
        } elseif (is_string($definitionsRaw) && $definitionsRaw !== '') {
            $decoded = json_decode($definitionsRaw, true);
            $definitions = is_array($decoded) ? $decoded : [];
        } else {
            $definitions = [];
        }
        $outputsValue = $execution->getAttribute('outputs');
        /** @var array<string, mixed> $outputs */
        $outputs = is_array($outputsValue) ? $outputsValue : [];
        $completed = array_keys($outputs);

        foreach ($definitions as $definition) {
            if (! is_array($definition) || ! is_string($definition['key'] ?? null)) {
                continue;
            }

            $key = $definition['key'];

            if (in_array($key, $completed, true)
                && (! ($definition['repeatable'] ?? false) || ! in_array($key, $repeatableStagesToReenter, true))
            ) {
                continue;
            }

            $dependencies = is_array($definition['dependencies'] ?? null) ? $definition['dependencies'] : [];
            $ready = true;
            foreach ($dependencies as $dependency) {
                if (! is_string($dependency) || ! in_array($dependency, $completed, true)) {
                    $ready = false;
                    break;
                }
            }

            if (! $ready) {
                continue;
            }

            $stage = new WorkflowStage;
            $stage->setRawAttributes([
                'workflow_id' => $execution->workflow_id,
                'key' => $key,
                'name' => $definition['name'] ?? $key,
                'instruction' => $definition['instruction'] ?? null,
                'sequence' => $definition['sequence'] ?? 0,
                'dependencies' => json_encode($dependencies, JSON_THROW_ON_ERROR),
                'expert_slugs' => json_encode($definition['expert_slugs'] ?? [], JSON_THROW_ON_ERROR),
                'capability_slugs' => json_encode($definition['capability_slugs'] ?? [], JSON_THROW_ON_ERROR),
                'capability_input_contract' => json_encode($definition['capability_input_contract'] ?? [], JSON_THROW_ON_ERROR),
                'capability_output_contract' => json_encode($definition['capability_output_contract'] ?? [], JSON_THROW_ON_ERROR),
                'input_contract' => json_encode($definition['input_contract'] ?? [], JSON_THROW_ON_ERROR),
                'output_contract' => json_encode($definition['output_contract'] ?? [], JSON_THROW_ON_ERROR),
                'repeatable' => (bool) ($definition['repeatable'] ?? false),
                'completion_criteria' => json_encode($definition['completion_criteria'] ?? [], JSON_THROW_ON_ERROR),
            ]);

            return $stage;
        }

        return null;
    }
}