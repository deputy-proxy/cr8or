<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class WorkflowVersionService
{
    public function publish(Workflow $workflow, User $actor, ?string $idempotencyKey = null): WorkflowVersion
    {
        Gate::forUser($actor)->authorize('view', $workflow);

        return DB::transaction(function () use ($workflow, $actor, $idempotencyKey): WorkflowVersion {
            /** @var Workflow $workflow */
            $workflow = Workflow::query()->lockForUpdate()->whereKey($workflow->getKey())->firstOrFail();

            if ($idempotencyKey !== null) {
                $existing = WorkflowVersion::query()
                    ->where('workflow_id', $workflow->getKey())
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    return $existing->status === WorkflowVersion::STATUS_DRAFT
                        ? $this->publishVersionLocked($existing, $workflow)
                        : $existing;
                }
            }

            $version = $this->createDraftFromWorkflowLocked($workflow, $actor, $idempotencyKey);

            return $this->publishVersionLocked($version, $workflow);
        });
    }

    public function publishVersion(WorkflowVersion $version, User $actor, ?string $idempotencyKey = null): WorkflowVersion
    {
        Gate::forUser($actor)->authorize('view', $version->workflow);

        return DB::transaction(function () use ($version, $idempotencyKey): WorkflowVersion {
            /** @var WorkflowVersion $version */
            $version = WorkflowVersion::query()->lockForUpdate()->whereKey($version->getKey())->firstOrFail();
            /** @var Workflow $workflow */
            $workflow = Workflow::query()->lockForUpdate()->whereKey($version->workflow_id)->firstOrFail();

            if ($version->status === WorkflowVersion::STATUS_PUBLISHED) {
                return $version;
            }

            if ($version->status !== WorkflowVersion::STATUS_DRAFT) {
                throw new AuthorizationException('Only draft WorkflowVersions can be published.');
            }

            if ($idempotencyKey !== null && $version->idempotency_key === null) {
                $version->setAttribute('idempotency_key', $idempotencyKey);
            }

            return $this->publishVersionLocked($version, $workflow);
        });
    }

    public function createDraft(WorkflowVersion $source, User $actor, ?string $idempotencyKey = null): WorkflowVersion
    {
        $sourceWorkflow = $source->workflow()->firstOrFail();
        Gate::forUser($actor)->authorize('view', $sourceWorkflow);

        return DB::transaction(function () use ($source, $actor, $idempotencyKey): WorkflowVersion {
            $source->loadMissing('workflow');
            /** @var Workflow $sourceWorkflow */
            $sourceWorkflow = $source->workflow;

            if ($idempotencyKey !== null) {
                $existing = WorkflowVersion::query()
                    ->where('workflow_id', $source->workflow_id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            $nextVersion = ((int) WorkflowVersion::query()->where('workflow_id', $source->workflow_id)->max('version')) + 1;

            return WorkflowVersion::query()->create([
                'workflow_id' => $source->workflow_id,
                'enterprise_id' => $source->enterprise_id,
                'version' => $nextVersion,
                'status' => WorkflowVersion::STATUS_DRAFT,
                'name' => $source->name,
                'purpose' => $source->purpose,
                'execution_policy' => $source->execution_policy ?? [],
                'completion_criteria' => $source->completion_criteria ?? [],
                'stage_definitions' => $source->stage_definitions ?? [],
                'idempotency_key' => $idempotencyKey,
                'created_by' => $actor->getKey(),
            ]);
        });
    }

    private function createDraftFromWorkflowLocked(Workflow $workflow, User $actor, ?string $idempotencyKey): WorkflowVersion
    {
        $nextVersion = ((int) WorkflowVersion::query()->where('workflow_id', $workflow->getKey())->max('version')) + 1;
        $stages = $workflow->stages()->get()->map(fn ($stage): array => [
            'key' => $stage->key,
            'name' => $stage->name,
            'instruction' => $stage->instruction,
            'sequence' => $stage->sequence,
            'dependencies' => $stage->dependencies ?? [],
            'expert_slugs' => $stage->expert_slugs ?? [],
            'capability_slugs' => $stage->capability_slugs ?? [],
            'capability_input_contract' => $stage->capability_input_contract ?? [],
            'capability_output_contract' => $stage->capability_output_contract ?? [],
            'input_contract' => $stage->input_contract ?? [],
            'output_contract' => $stage->output_contract ?? [],
            'repeatable' => (bool) $stage->repeatable,
            'completion_criteria' => $stage->completion_criteria ?? [],
        ])->values()->all();

        if ($stages === []) {
            throw new AuthorizationException('A WorkflowVersion cannot be published without stages.');
        }

        return WorkflowVersion::query()->create([
            'workflow_id' => $workflow->getKey(),
            'enterprise_id' => $workflow->enterprise_id,
            'version' => $nextVersion,
            'status' => WorkflowVersion::STATUS_DRAFT,
            'name' => $workflow->name,
            'purpose' => $workflow->purpose,
            'execution_policy' => $workflow->execution_policy ?? [],
            'completion_criteria' => $workflow->completion_criteria ?? [],
            'stage_definitions' => $stages,
            'idempotency_key' => $idempotencyKey,
            'created_by' => $actor->getKey(),
        ]);
    }

    private function publishVersionLocked(WorkflowVersion $version, Workflow $workflow): WorkflowVersion
    {
        WorkflowVersion::query()
            ->where('workflow_id', $workflow->getKey())
            ->where('status', WorkflowVersion::STATUS_PUBLISHED)
            ->whereKeyNot($version->getKey())
            ->update(['status' => WorkflowVersion::STATUS_RETIRED, 'retired_at' => now()]);

        $version->publish()->save();

        $workflow->setAttribute('published_version_id', $version->getKey());
        $workflow->setAttribute('version', $version->version);
        $workflow->save();

        return $version->refresh();
    }
}