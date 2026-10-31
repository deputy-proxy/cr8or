<?php

namespace App\Models;

use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * @property int $enterprise_id
 * @property int|null $project_id
 * @property int|null $initiative_id
 * @property int|null $parent_task_id
 *
 * A Task is the canonical actionable work entity, owned by an Enterprise and optionally scoped to one Project or Initiative.
 * It owns hierarchy, scheduling, workflow associations, decisions, assignments, and dependency endpoints.
 */
#[Fillable(['enterprise_id', 'project_id', 'initiative_id', 'parent_task_id', 'name', 'description', 'status', 'priority', 'due_at'])]
class Task extends Model
{
    protected $attributes = ['status' => 'todo'];

    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'closed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (Task $task): void {
            $task->validateScopeAndHierarchy();
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

    /** @return BelongsTo<Initiative, $this> */
    public function initiative(): BelongsTo
    {
        return $this->belongsTo(Initiative::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_task_id');
    }

    /** @return HasMany<Task, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_task_id');
    }

    /** @return HasMany<Workflow, $this> */
    public function workflows(): HasMany
    {
        return $this->hasMany(Workflow::class);
    }

    /** @return HasMany<Decision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(Decision::class);
    }

    /** @return MorphMany<Assignment, $this> */
    public function assignments(): MorphMany
    {
        return $this->morphMany(Assignment::class, 'assignable');
    }

    /** @return MorphMany<Dependency, $this> */
    public function predecessorDependencies(): MorphMany
    {
        return $this->morphMany(Dependency::class, 'predecessor');
    }

    /** @return MorphMany<Dependency, $this> */
    public function successorDependencies(): MorphMany
    {
        return $this->morphMany(Dependency::class, 'successor');
    }

    private function validateScopeAndHierarchy(): void
    {
        $hasProject = $this->project_id !== null;
        $hasInitiative = $this->initiative_id !== null;

        if ($hasProject && $hasInitiative) {
            throw new LogicException('A Task can belong to a Project or Initiative, not both.');
        }

        $parentEnterpriseId = match (true) {
            $hasProject => Project::query()->whereKey($this->project_id)->value('enterprise_id'),
            $hasInitiative => Initiative::query()->whereKey($this->initiative_id)->value('enterprise_id'),
            default => null,
        };

        if ($parentEnterpriseId !== null && (int) $parentEnterpriseId !== (int) $this->enterprise_id) {
            throw new LogicException('Task Project or Initiative must belong to the same Enterprise.');
        }
        if (($hasProject || $hasInitiative) && $parentEnterpriseId === null) {
            throw new LogicException('Task Project or Initiative must exist and belong to the same Enterprise.');
        }

        if ($this->exists) {
            foreach ($this->children()->get(['id', 'enterprise_id', 'project_id', 'initiative_id']) as $child) {
                if ((int) $child->enterprise_id !== (int) $this->enterprise_id
                    || $child->project_id !== $this->project_id
                    || $child->initiative_id !== $this->initiative_id) {
                    throw new LogicException('A Task cannot change Enterprise, Project, or Initiative scope while its children remain in another scope.');
                }
            }
        }

        if ($this->parent_task_id === null) {
            return;
        }

        if ($this->exists && (int) $this->getKey() === (int) $this->parent_task_id) {
            throw new LogicException('A Task cannot be its own parent.');
        }

        $parent = Task::query()->find($this->parent_task_id);
        if ($parent === null
            || (int) $parent->enterprise_id !== (int) $this->enterprise_id
            || $parent->project_id !== $this->project_id
            || $parent->initiative_id !== $this->initiative_id) {
            throw new LogicException('Parent Task must belong to the same Enterprise and Project or Initiative.');
        }

        $visited = [];
        while ($parent !== null) {
            $parentId = (int) $parent->getKey();
            if (isset($visited[$parentId]) || ($this->exists && $parentId === (int) $this->getKey())) {
                throw new LogicException('Task hierarchy cannot contain cycles.');
            }
            $visited[$parentId] = true;
            $parent = $parent->parent_task_id === null ? null : Task::query()->find($parent->parent_task_id);
        }
    }
}
