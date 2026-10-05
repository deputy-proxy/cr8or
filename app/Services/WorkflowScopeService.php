<?php

namespace App\Services;

use App\Models\Enterprise;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\WorkflowVersion;
use Illuminate\Support\Facades\DB;
use LogicException;

final class WorkflowScopeService
{
    public function change(
        User $actor,
        Workflow $workflow,
        bool $enterpriseSpecific,
        ?Enterprise $enterprise = null,
    ): Workflow {
        return DB::transaction(function () use ($actor, $workflow, $enterpriseSpecific, $enterprise): Workflow {
            /** @var Workflow $workflow */
            $workflow = Workflow::query()
                ->lockForUpdate()
                ->whereKey($workflow->getKey())
                ->firstOrFail();

            if (
                $workflow->isEnterpriseSpecific() === $enterpriseSpecific
                && (int) $workflow->enterprise_id === (int) $enterprise?->getKey()
            ) {
                return $workflow;
            }

            if ($enterpriseSpecific && $enterprise === null) {
                throw new LogicException('Enterprise-specific Workflow requires an enterprise.');
            }

            if (
                ! $enterpriseSpecific
                && ($workflow->project_id !== null || $workflow->task_id !== null || $workflow->work_item_id !== null)
            ) {
                throw new LogicException(
                    'An enterprise-scoped Project, Task, or Work Item must be removed before this Workflow can become generic.',
                );
            }

            $hasActiveExecution = $workflow->executions()
                ->whereNotIn('status', [
                    WorkflowExecution::STATUS_COMPLETED,
                    WorkflowExecution::STATUS_FAILED,
                ])
                ->exists();

            if ($hasActiveExecution) {
                throw new LogicException(
                    'Workflow scope cannot be changed while an execution is active. Complete or fail the execution first.',
                );
            }

            $workflow->enterprise_specific = $enterpriseSpecific;
            $workflow->enterprise_id = $enterpriseSpecific ? $enterprise->getKey() : null;
            $workflow->save();

            WorkflowVersion::query()
                ->where('workflow_id', $workflow->getKey())
                ->where('status', WorkflowVersion::STATUS_DRAFT)
                ->update(['enterprise_id' => $workflow->enterprise_id]);

            $published = WorkflowVersion::query()
                ->where('workflow_id', $workflow->getKey())
                ->where('status', WorkflowVersion::STATUS_PUBLISHED)
                ->latest('version')
                ->first();

            if ($published !== null) {
                $nextVersion = ((int) WorkflowVersion::query()
                    ->where('workflow_id', $workflow->getKey())
                    ->max('version')) + 1;

                $version = WorkflowVersion::query()->create([
                    'workflow_id' => $workflow->getKey(),
                    'enterprise_id' => $workflow->enterprise_id,
                    'version' => $nextVersion,
                    'status' => WorkflowVersion::STATUS_DRAFT,
                    'name' => $workflow->name,
                    'purpose' => $workflow->purpose,
                    'execution_policy' => $workflow->execution_policy ?? [],
                    'completion_criteria' => $workflow->completion_criteria ?? [],
                    'stage_definitions' => $published->stage_definitions ?? [],
                    'idempotency_key' => 'scope-change:'.$workflow->getKey().':'.$nextVersion,
                    'created_by' => $actor->getKey(),
                ]);

                app(WorkflowVersionService::class)->publishVersion($version, $actor);
            }

            return $workflow->refresh();
        });
    }
}
