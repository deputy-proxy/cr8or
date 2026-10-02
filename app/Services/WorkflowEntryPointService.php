<?php

namespace App\Services;

use App\Models\Enterprise;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\WorkflowStage;
use App\Models\WorkflowVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class WorkflowEntryPointService
{
    /** @param array<string, mixed> $input */
    public function create(User $actor, Enterprise $enterprise, array $input): Workflow
    {
        Gate::forUser($actor)->authorize('view', $enterprise);

        return DB::transaction(function () use ($enterprise, $input): Workflow {
            $workflow = Workflow::query()->create([
                'enterprise_id' => $enterprise->getKey(),
                'project_id' => $input['project_id'] ?? null,
                'task_id' => $input['task_id'] ?? null,
                'work_item_id' => $input['work_item_id'] ?? null,
                'name' => $input['name'],
                'purpose' => $input['purpose'] ?? null,
                'execution_policy' => $input['execution_policy'] ?? [],
                'completion_criteria' => $input['completion_criteria'] ?? [],
                'status' => Workflow::STATUS_PENDING,
            ]);

            foreach ($input['stages'] as $stage) {
                WorkflowStage::query()->create([
                    'workflow_id' => $workflow->getKey(),
                    'key' => $stage['key'],
                    'name' => $stage['name'] ?? $stage['key'],
                    'sequence' => $stage['sequence'] ?? 0,
                    'dependencies' => $stage['dependencies'] ?? [],
                    'expert_slugs' => $stage['expert_slugs'] ?? [],
                    'capability_slugs' => $stage['capability_slugs'] ?? [],
                    'input_contract' => $stage['input_contract'] ?? [],
                    'output_contract' => $stage['output_contract'] ?? [],
                    'repeatable' => (bool) ($stage['repeatable'] ?? false),
                    'completion_criteria' => $stage['completion_criteria'] ?? [],
                ]);
            }

            return $workflow->refresh();
        });
    }

    /** @return array{items: list<array<string, mixed>>, pagination: array<string, int>} */
    public function discover(User $actor, Enterprise $enterprise, ?string $search = null, int $perPage = 20, int $page = 1): array
    {
        Gate::forUser($actor)->authorize('view', $enterprise);

        $query = Workflow::query()->where('enterprise_id', $enterprise->getKey())->with('publishedVersion');

        if ($search !== null && $search !== '') {
            $query->where(fn ($builder) => $builder
                ->where('name', 'like', "%{$search}%")
                ->orWhere('purpose', 'like', "%{$search}%"));
        }

        $total = $query->count();
        $workflows = $query->orderBy('id')->forPage($page, $perPage)->get();

        /** @var list<array<string, mixed>> $items */
        $items = $workflows->map(fn (Workflow $workflow): array => [
            'id' => $workflow->getKey(),
            'name' => $workflow->name,
            'purpose' => $workflow->purpose,
            'status' => $workflow->status,
            'published_version_id' => $workflow->published_version_id,
            'published_version' => $workflow->publishedVersion?->version,
            'has_published_version' => $workflow->publishedVersion?->status === WorkflowVersion::STATUS_PUBLISHED,
        ])->values()->all();

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    public function publish(User $actor, Workflow $workflow, ?string $idempotencyKey = null): WorkflowVersion
    {
        return app(WorkflowVersionService::class)->publish($workflow, $actor, $idempotencyKey);
    }

    /** @param array<string, mixed> $input */
    public function start(User $actor, Workflow $workflow, array $input, string $idempotencyKey, ?string $correlationId = null): WorkflowExecution
    {
        return app(WorkflowExecutionService::class)->start($actor, $workflow, $input, $idempotencyKey, $correlationId);
    }

    /** @return array<string, mixed> */
    public function inspect(User $actor, WorkflowExecution $execution): array
    {
        return app(WorkflowExecutionService::class)->inspect($actor, $execution);
    }

    public function resume(User $actor, WorkflowExecution $execution): WorkflowExecution
    {
        return app(WorkflowExecutionService::class)->continue($actor, $execution);
    }
}