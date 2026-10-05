<?php

namespace App\Models;

use Database\Factories\WorkflowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['enterprise_specific', 'enterprise_id', 'project_id', 'task_id', 'work_item_id', 'name', 'canonical_key', 'purpose', 'version', 'published_version_id', 'execution_policy', 'completion_criteria', 'status'])]
/** @property bool $enterprise_specific
 * @property int|null $version
 * @property array<string, mixed>|null $completion_criteria
 * @property array<string, mixed>|null $execution_policy
 */
class Workflow extends Model
{
    /** @use HasFactory<WorkflowFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    protected static function booted(): void
    {
        static::saving(function (Workflow $w): void {
            $w->version = $w->version ?? 1;
            $w->status = $w->status ?? self::STATUS_PENDING;
            $w->validateState();
            $w->validateScope();
        });
    }

    protected function casts(): array
    {
        return ['enterprise_specific' => 'boolean', 'execution_policy' => 'array', 'completion_criteria' => 'array'];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Workflow>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Workflow>
     */
    public function scopeForCanonicalKey(\Illuminate\Database\Eloquent\Builder $query, Enterprise $enterprise, string $canonicalKey): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('canonical_key', $canonicalKey)
            ->where(function ($query) use ($enterprise): void {
                $query->where(function ($query) use ($enterprise): void {
                    $query->where('enterprise_specific', true)->where('enterprise_id', $enterprise->getKey());
                })->orWhere(function ($query): void {
                    $query->where('enterprise_specific', false)->whereNull('enterprise_id');
                });
            })
            ->orderByDesc('enterprise_specific');
    }

    public function isEnterpriseSpecific(): bool
    {
        return (bool) $this->enterprise_specific;
    }

    public function isAvailableForEnterprise(Enterprise $enterprise): bool
    {
        return ! $this->isEnterpriseSpecific()
            || (int) $this->enterprise_id === (int) $enterprise->getKey();
    }

    /** @param Builder<Workflow> $query
     * @return Builder<Workflow>
     */
    public function scopeAvailableForEnterprise(Builder $query, Enterprise $enterprise): Builder
    {
        return $query->where(function ($query) use ($enterprise): void {
            $query->where(function ($query): void {
                $query->where('enterprise_specific', false)->whereNull('enterprise_id');
            })->orWhere(function ($query) use ($enterprise): void {
                $query->where('enterprise_specific', true)->where('enterprise_id', $enterprise->getKey());
            });
        });
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<WorkItem, $this> */
    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    /** @return HasMany<WorkflowStage, $this> */
    public function stages(): HasMany
    {
        return $this->hasMany(WorkflowStage::class)->orderBy('sequence');
    }

    /** @return HasMany<AgentExecution, $this> */
    public function agentExecutions(): HasMany
    {
        return $this->hasMany(AgentExecution::class);
    }

    /** @return HasMany<WorkflowExecution, $this> */
    public function executions(): HasMany
    {
        return $this->hasMany(WorkflowExecution::class);
    }

    /** @return BelongsTo<WorkflowVersion, $this> */
    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'published_version_id');
    }

    /** @return HasMany<WorkflowVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class);
    }

    public function transitionTo(string $status): static
    {
        $allowed = match ($this->status) {
            self::STATUS_PENDING => [self::STATUS_RUNNING, self::STATUS_FAILED],self::STATUS_RUNNING => [self::STATUS_SUCCEEDED, self::STATUS_FAILED],self::STATUS_SUCCEEDED,self::STATUS_FAILED => [],default => throw new LogicException('Workflow has no valid lifecycle state.')
        };
        if (! in_array($status, [self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_SUCCEEDED, self::STATUS_FAILED], true)) {
            throw new LogicException("Invalid workflow status [{$status}].");
        }if (! in_array($status, $allowed, true)) {
            throw new LogicException(sprintf('Workflow cannot transition from [%s] to [%s].', $this->status, $status));
        }$this->status = $status;

        return $this;
    }

    public function completionSatisfied(AgentExecution|WorkflowExecution $execution): bool
    {
        $stages = $this->stages()->get();
        if ($stages->isEmpty()) {
            return false;
        }

        if ($execution instanceof WorkflowExecution) {
            if (! $execution->workflow_id || $execution->workflow_id !== $this->getKey()) {
                return false;
            }

            $outputsValue = $execution->getAttribute('outputs');
            $outputs = is_array($outputsValue) ? $outputsValue : [];
            $keys = array_values(array_filter(array_keys($outputs), 'is_string'));

            if (! $stages->every(fn (WorkflowStage $stage): bool => in_array($stage->key, $keys, true))) {
                return false;
            }
        } else {
            $completed = $execution->steps()
                ->where('type', AgentExecutionStep::TYPE_WORKFLOW)
                ->where('status', AgentExecutionStep::STATUS_COMPLETED)
                ->pluck('workflow_stage_id')
                ->filter()
                ->all();

            if (! $stages->every(fn (WorkflowStage $stage): bool => in_array($stage->getKey(), $completed, true))) {
                return false;
            }

            $keys = $stages->whereIn('id', $completed)->pluck('key')->all();
        }

        $criteriaValue = $this->getAttribute('completion_criteria');
        $criteria = is_array($criteriaValue) ? $criteriaValue : [];
        foreach (($criteria['required_stage_keys'] ?? []) as $key) {
            if (! is_string($key) || ! in_array($key, $keys, true)) {
                return false;
            }
        }

        return true;
    }

    private function validateState(): void
    {
        if (! in_array($this->status, [self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_SUCCEEDED, self::STATUS_FAILED], true)) {
            throw new LogicException("Invalid workflow status [{$this->status}].");
        }if ((int) $this->version < 1) {
            throw new LogicException('Workflow version must be positive.');
        }
    }

    private function validateScope(): void
    {
        if ($this->isEnterpriseSpecific()) {
            if ($this->enterprise_id === null) {
                throw new LogicException('Enterprise-specific Workflow must have an enterprise.');
            }
        } elseif ($this->enterprise_id !== null) {
            throw new LogicException('Generic Workflow must not have an enterprise.');
        }

        if (! $this->isEnterpriseSpecific() && $this->canonical_key !== null) {
            $duplicate = static::query()
                ->whereKeyNot($this->getKey())
                ->where('enterprise_specific', false)
                ->whereNull('enterprise_id')
                ->where('canonical_key', $this->canonical_key)
                ->exists();

            if ($duplicate) {
                throw new LogicException("Generic Workflow canonical key [{$this->canonical_key}] already exists.");
            }
        }

        foreach (['project_id' => Project::class, 'task_id' => Task::class, 'work_item_id' => WorkItem::class] as $field => $model) {
            $id = $this->{$field};
            if ($id === null) {
                continue;
            }

            if ($this->enterprise_id === null) {
                throw new LogicException("Generic Workflow cannot reference enterprise-scoped {$field}.");
            }

            $record = $model::query()->find($id);
            if ($record === null || $record->enterprise_id !== $this->enterprise_id) {
                throw new LogicException("Workflow {$field} must belong to its enterprise.");
            }
        }
    }
}