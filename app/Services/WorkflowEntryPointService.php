<?php

namespace App\Services;

use App\Models\Enterprise;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\WorkflowStage;
use App\Models\WorkflowVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class WorkflowEntryPointService
{
    public function __construct(
        private readonly ExpertCapabilityResolver $expertCapabilities,
    ) {}

    /** @param array<string, mixed> $input */
    public function create(User $actor, ?Enterprise $enterprise, array $input): Workflow
    {
        $enterpriseSpecific = (bool) ($input['enterprise_specific'] ?? true);

        if ($enterpriseSpecific) {
            if (! $enterprise instanceof Enterprise) {
                throw new AuthorizationException('Enterprise-specific Workflow creation requires an enterprise.');
            }

            Gate::forUser($actor)->authorize('createForEnterprise', [Workflow::class, $enterprise]);
        } else {
            if ($enterprise !== null) {
                throw new AuthorizationException('Generic Workflow must not be assigned to an enterprise.');
            }

            Gate::forUser($actor)->authorize('create', Workflow::class);
        }

        return DB::transaction(function () use ($enterprise, $enterpriseSpecific, $input): Workflow {
            $workflow = Workflow::query()->create([
                'enterprise_specific' => $enterpriseSpecific,
                'enterprise_id' => $enterprise?->getKey(),
                'project_id' => $input['project_id'] ?? null,
                'task_id' => $input['task_id'] ?? null,
                'work_item_id' => $input['work_item_id'] ?? null,
                'name' => $input['name'],
                'canonical_key' => $input['canonical_key'] ?? null,
                'purpose' => $input['purpose'] ?? null,
                'execution_policy' => $input['execution_policy'] ?? [],
                'completion_criteria' => $input['completion_criteria'] ?? [],
                'status' => Workflow::STATUS_PENDING,
            ]);

            foreach ($input['stages'] as $stage) {
                $expertSlugs = $this->list($stage['expert_slugs'] ?? []);
                $capabilitySlugs = $this->list($stage['capability_slugs'] ?? []);

                if (count($expertSlugs) !== 1 || count($capabilitySlugs) !== 1) {
                    throw new AuthorizationException(
                        'Each new Workflow stage must declare exactly one Expert and one Capability.',
                    );
                }

                $capability = $this->expertCapabilities->resolve($expertSlugs[0], $capabilitySlugs[0]);

                WorkflowStage::query()->create([
                    'workflow_id' => $workflow->getKey(),
                    'key' => $stage['key'],
                    'name' => $stage['name'] ?? $stage['key'],
                    'instruction' => $stage['instruction'] ?? null,
                    'sequence' => $stage['sequence'] ?? 0,
                    'dependencies' => $stage['dependencies'] ?? [],
                    'expert_slugs' => $expertSlugs,
                    'capability_slugs' => $capabilitySlugs,
                    'capability_input_contract' => $capability->inputContract,
                    'capability_output_contract' => $capability->outputContract,
                    'input_contract' => $stage['input_contract'] ?? [],
                    'output_contract' => ! empty($stage['output_contract'] ?? null)
                        ? $stage['output_contract']
                        : $capability->outputContract,
                    'repeatable' => (bool) ($stage['repeatable'] ?? false),
                    'completion_criteria' => $stage['completion_criteria'] ?? [],
                ]);
            }

            return $workflow->refresh();
        });
    }

    public function duplicate(User $actor, Workflow $workflow): Workflow
    {
        if ($workflow->isEnterpriseSpecific()) {
            Gate::forUser($actor)->authorize('createForEnterprise', [Workflow::class, $workflow->enterprise]);
        } else {
            Gate::forUser($actor)->authorize('create', Workflow::class);
        }

        return DB::transaction(function () use ($workflow): Workflow {
            /** @var Workflow $source */
            $source = Workflow::query()
                ->with('stages')
                ->lockForUpdate()
                ->whereKey($workflow->getKey())
                ->firstOrFail();

            $name = $this->nextDuplicateName($source);
            $canonicalKey = $this->nextDuplicateCanonicalKey($source);

            $duplicate = $source->replicate([
                'version',
                'published_version_id',
                'status',
            ]);
            $duplicate->fill([
                'name' => $name,
                'canonical_key' => $canonicalKey,
                'version' => 1,
                'published_version_id' => null,
                'status' => Workflow::STATUS_PENDING,
            ]);
            $duplicate->save();

            foreach ($source->stages as $stage) {
                $duplicate->stages()->save($stage->replicate());
            }

            return $duplicate->refresh();
        });
    }

    private function nextDuplicateName(Workflow $workflow): string
    {
        $suffix = 1;

        do {
            $label = $suffix === 1 ? ' (Copy)' : ' (Copy '.$suffix.')';
            $name = mb_substr($workflow->name, 0, 255 - mb_strlen($label)).$label;
            $suffix++;
        } while (Workflow::query()
            ->where('enterprise_specific', $workflow->enterprise_specific)
            ->when($workflow->isEnterpriseSpecific(),
                fn ($query) => $query->where('enterprise_id', $workflow->enterprise_id),
                fn ($query) => $query->whereNull('enterprise_id'),
            )
            ->where('name', $name)
            ->exists());

        return $name;
    }

    private function nextDuplicateCanonicalKey(Workflow $workflow): ?string
    {
        if (! filled($workflow->canonical_key)) {
            return null;
        }

        $suffix = 1;

        do {
            $label = $suffix === 1 ? '.copy' : '.copy-'.$suffix;
            $key = mb_substr($workflow->canonical_key, 0, 150 - mb_strlen($label)).$label;
            $suffix++;
        } while (Workflow::query()
            ->where('enterprise_specific', $workflow->enterprise_specific)
            ->when($workflow->isEnterpriseSpecific(),
                fn ($query) => $query->where('enterprise_id', $workflow->enterprise_id),
                fn ($query) => $query->whereNull('enterprise_id'),
            )
            ->where('canonical_key', $key)
            ->exists());

        return $key;
    }

    /** @param array<string, mixed> $input */
    public function update(User $actor, Workflow $workflow, array $input): Workflow
    {
        Gate::forUser($actor)->authorize('update', $workflow);

        return DB::transaction(function () use ($workflow, $input): Workflow {
            /** @var Workflow $workflow */
            $workflow = Workflow::query()->lockForUpdate()->whereKey($workflow->getKey())->firstOrFail();

            $attributes = array_intersect_key($input, array_flip([
                'name',
                'canonical_key',
                'purpose',
                'execution_policy',
                'completion_criteria',
                'project_id',
                'task_id',
                'work_item_id',
            ]));

            if ($attributes !== []) {
                $workflow->fill($attributes);
                $workflow->save();
            }

            if (array_key_exists('stages', $input)) {
                $stages = is_array($input['stages']) ? $input['stages'] : [];
                $keys = array_values(array_filter(array_map(
                    static function (mixed $stage): ?string {
                        return is_array($stage) && is_string($stage['key'] ?? null)
                            ? $stage['key']
                            : null;
                    },
                    $stages,
                )));

                WorkflowStage::query()
                    ->where('workflow_id', $workflow->getKey())
                    ->when($keys !== [], fn ($query) => $query->whereNotIn('key', $keys))
                    ->delete();

                foreach ($stages as $stage) {
                    $expertSlugs = $this->list($stage['expert_slugs'] ?? []);
                    $capabilitySlugs = $this->list($stage['capability_slugs'] ?? []);

                    if (count($expertSlugs) !== 1 || count($capabilitySlugs) !== 1) {
                        throw new AuthorizationException(
                            'Each Workflow stage must declare exactly one Expert and one Capability.',
                        );
                    }

                    $capability = $this->expertCapabilities->resolve($expertSlugs[0], $capabilitySlugs[0]);
                    $stageModel = WorkflowStage::query()->firstOrNew([
                        'workflow_id' => $workflow->getKey(),
                        'key' => $stage['key'],
                    ]);

                    $stageModel->fill([
                        'name' => $stage['name'] ?? $stage['key'],
                        'instruction' => $stage['instruction'] ?? null,
                        'sequence' => $stage['sequence'] ?? 1,
                        'dependencies' => $stage['dependencies'] ?? [],
                        'expert_slugs' => $expertSlugs,
                        'capability_slugs' => $capabilitySlugs,
                        'capability_input_contract' => $capability->inputContract,
                        'capability_output_contract' => $capability->outputContract,
                        'input_contract' => $stage['input_contract'] ?? [],
                        'output_contract' => filled($stage['output_contract'] ?? null)
                            ? $stage['output_contract']
                            : $capability->outputContract,
                        'repeatable' => (bool) ($stage['repeatable'] ?? false),
                        'completion_criteria' => $stage['completion_criteria'] ?? [],
                    ]);
                    $stageModel->save();
                }
            }

            app(WorkflowDefinitionValidator::class)->validateWorkflow($workflow->refresh());

            return $workflow->refresh();
        });
    }

    /**
     * @return list<string>
     */
    private function list(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $item): bool => is_string($item) && trim($item) !== '',
        ));
    }

    /** @return array{items: list<array<string, mixed>>, pagination: array<string, int>} */
    public function discover(User $actor, Enterprise $enterprise, ?string $canonicalKey = null, ?string $search = null, int $perPage = 20, int $page = 1): array
    {
        Gate::forUser($actor)->authorize('view', $enterprise);

        if ($canonicalKey !== null && $canonicalKey !== '') {
            $workflow = Workflow::query()
                ->availableForEnterprise($enterprise)
                ->where('canonical_key', $canonicalKey)
                ->orderByDesc('enterprise_specific')
                ->with('publishedVersion')
                ->first();

            return [
                'items' => $workflow === null ? [] : [[
                    'id' => $workflow->getKey(),
                    'name' => $workflow->name,
                    'purpose' => $workflow->purpose,
                    'status' => $workflow->status,
                    'canonical_key' => $workflow->canonical_key,
                    'published_version_id' => $workflow->published_version_id,
                    'published_version' => $workflow->publishedVersion?->version,
                    'published_version_status' => $workflow->publishedVersion?->status,
                    'has_published_version' => $workflow->publishedVersion?->status === WorkflowVersion::STATUS_PUBLISHED,
                ]],
                'pagination' => ['page' => 1, 'per_page' => 1, 'total' => $workflow === null ? 0 : 1, 'last_page' => 1],
            ];
        }

        $query = Workflow::query()->availableForEnterprise($enterprise)->with('publishedVersion');

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
            'canonical_key' => $workflow->canonical_key,
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

    /** @return array<string, mixed> */
    public function get(User $actor, Workflow $workflow, ?Enterprise $enterprise = null): array
    {
        if ($enterprise instanceof Enterprise) {
            Gate::forUser($actor)->authorize('viewForEnterprise', [$workflow, $enterprise]);
        } else {
            Gate::forUser($actor)->authorize('view', $workflow);
        }

        $workflow->load(['enterprise', 'stages', 'publishedVersion']);

        $stage = static fn (WorkflowStage $stage): array => [
            'id' => $stage->getKey(),
            'key' => $stage->key,
            'name' => $stage->name,
            'instruction' => $stage->instruction,
            'sequence' => $stage->sequence,
            'dependencies' => $stage->dependencies,
            'expert_slugs' => $stage->expert_slugs,
            'capability_slugs' => $stage->capability_slugs,
            'capability_input_contract' => $stage->capability_input_contract,
            'capability_output_contract' => $stage->capability_output_contract,
            'input_contract' => $stage->input_contract,
            'output_contract' => $stage->output_contract,
            'repeatable' => $stage->repeatable,
            'completion_criteria' => $stage->completion_criteria,
        ];

        return [
            'id' => $workflow->getKey(),
            'enterprise_specific' => $workflow->isEnterpriseSpecific(),
            'enterprise_id' => $workflow->enterprise_id,
            'name' => $workflow->name,
            'canonical_key' => $workflow->canonical_key,
            'purpose' => $workflow->purpose,
            'status' => $workflow->status,
            'version' => $workflow->version,
            'execution_policy' => $workflow->execution_policy,
            'completion_criteria' => $workflow->completion_criteria,
            'published_version_id' => $workflow->published_version_id,
            'stages' => $workflow->stages->map($stage)->values()->all(),
            'published_version' => $workflow->publishedVersion === null ? null : [
                'id' => $workflow->publishedVersion->getKey(),
                'version' => $workflow->publishedVersion->version,
                'status' => $workflow->publishedVersion->status,
                'published_at' => $workflow->publishedVersion->published_at,
                'stage_definitions' => $workflow->publishedVersion->stage_definitions,
                'execution_policy' => $workflow->publishedVersion->execution_policy,
                'completion_criteria' => $workflow->publishedVersion->completion_criteria,
            ],
        ];
    }

    public function publish(User $actor, Workflow $workflow, ?string $idempotencyKey = null, ?Enterprise $enterprise = null): WorkflowVersion
    {
        if ($enterprise instanceof Enterprise) {
            Gate::forUser($actor)->authorize('viewForEnterprise', [$workflow, $enterprise]);
        } else {
            Gate::forUser($actor)->authorize('view', $workflow);
        }

        return app(WorkflowVersionService::class)->publish($workflow, $actor, $idempotencyKey);
    }

    /** @param array<string, mixed> $input */
    public function start(User $actor, Workflow $workflow, array $input, string $idempotencyKey, ?string $correlationId = null, bool $returnFailed = false, ?Enterprise $enterprise = null): WorkflowExecution
    {
        $enterprise ??= $workflow->enterprise;

        if (! $enterprise instanceof Enterprise) {
            throw new AuthorizationException('Generic Workflow execution requires an enterprise context.');
        }

        Gate::forUser($actor)->authorize('viewForEnterprise', [$workflow, $enterprise]);

        return app(WorkflowExecutionService::class)->start($actor, $workflow, $input, $idempotencyKey, $correlationId, $returnFailed, $enterprise);
    }

    /** @return array<string, mixed> */
    public function inspect(User $actor, WorkflowExecution $execution): array
    {
        return app(WorkflowExecutionService::class)->inspect($actor, $execution);
    }

    public function resume(User $actor, WorkflowExecution $execution, ?string $continuationToken = null): WorkflowExecution
    {
        return app(WorkflowExecutionService::class)->continue($actor, $execution, $continuationToken);
    }
}