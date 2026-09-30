<?php

namespace App\Models;

use Database\Factories\WorkflowStageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['workflow_id', 'key', 'name', 'sequence', 'dependencies', 'expert_slugs', 'capability_slugs', 'input_contract', 'output_contract', 'repeatable', 'completion_criteria'])]
/**
 * @property array<int, string>|null $dependencies
 * @property array<int, string>|null $expert_slugs
 * @property array<int, string>|null $capability_slugs
 * @property array<string, mixed>|null $input_contract
 * @property array<string, mixed>|null $output_contract
 * @property array<string, mixed>|null $completion_criteria
 */
class WorkflowStage extends Model
{
    /** @use HasFactory<WorkflowStageFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['dependencies' => 'array', 'expert_slugs' => 'array', 'capability_slugs' => 'array', 'input_contract' => 'array', 'output_contract' => 'array', 'repeatable' => 'boolean', 'completion_criteria' => 'array'];
    }

    /** @return BelongsTo<Workflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /** @param array<string, mixed> $output
     * @param  list<array<string, mixed>>  $capabilityResults
     */
    public function completionSatisfied(array $output, array $capabilityResults = []): bool
    {
        $criteriaValue = $this->getAttribute('completion_criteria');
        $criteria = is_array($criteriaValue) ? $criteriaValue : [];
        if (($criteria['requires_termination_completed'] ?? true) && ($output['termination'] ?? 'completed') !== 'completed') {
            return false;
        }
        foreach (($criteria['required_output_keys'] ?? []) as $key) {
            if (! is_string($key) || ! array_key_exists($key, $output)) {
                return false;
            }
        }

        $successful = collect($capabilityResults)->filter(
            fn (array $result): bool => in_array($result['status'] ?? null, ['succeeded', 'executed'], true),
        );
        foreach (($criteria['required_capability_results'] ?? []) as $required) {
            if (is_string($required)) {
                if (! $successful->contains(fn (array $result): bool => ($result['capability'] ?? null) === $required)) {
                    return false;
                }

                continue;
            }
            if (! is_array($required) || ! is_string($required['capability'] ?? null)) {
                return false;
            }
            $matched = $successful->first(fn (array $result): bool => ($result['capability'] ?? null) === $required['capability']);
            if (! is_array($matched)) {
                return false;
            }
            if (isset($required['result_key'])) {
                $result = is_array($matched['result'] ?? null) ? $matched['result'] : [];
                if (($result[$required['result_key']] ?? null) !== ($required['result_value'] ?? null)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param list<string> $completedStageKeys */
    public function assertDependenciesSatisfied(array $completedStageKeys): void
    {
        foreach (is_array($this->getAttribute('dependencies')) ? $this->getAttribute('dependencies') : [] as $d) {
            if (! is_string($d) || ! in_array($d, $completedStageKeys, true)) {
                throw new LogicException(sprintf('Workflow stage [%s] cannot execute before dependency [%s] is completed.', $this->key, (string) $d));
            }
        }
    }
}