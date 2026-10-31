<?php

namespace App\Models;

use Database\Factories\DecisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $enterprise_id
 * @property string $type
 * @property int|null $actor_id
 * @property string|null $actor_name
 * @property int|null $objective_id
 * @property int|null $strategy_id
 * @property int|null $initiative_id
 * @property int|null $project_id
 * @property int|null $task_id
 * @property string $title
 * @property string $summary
 * @property string|null $rationale
 * @property \Illuminate\Support\Carbon $decided_at
 */
#[Fillable([
    'enterprise_id', 'type', 'actor_id', 'actor_name', 'objective_id',
    'strategy_id', 'initiative_id', 'project_id', 'task_id',
    'title', 'summary', 'rationale', 'decided_at',
])]
class Decision extends Model
{
    /** @use HasFactory<DecisionFactory> */
    use HasFactory;

    /** @var list<string> */
    private const HISTORICAL_FIELDS = [
        'enterprise_id', 'type', 'actor_id', 'actor_name', 'objective_id',
        'strategy_id', 'initiative_id', 'project_id', 'task_id',
        'decided_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (Decision $decision): void {
            $decision->validateScope();

            if (! $decision->exists) {
                if ($decision->actor_id !== null && $decision->actor_name === null) {
                    $decision->actor_name = User::query()->whereKey($decision->actor_id)->value('name');
                }

                return;
            }

            foreach (self::HISTORICAL_FIELDS as $field) {
                $decision->{$field} = $decision->getRawOriginal($field);
            }
        });
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<Objective, $this> */
    public function objective(): BelongsTo
    {
        return $this->belongsTo(Objective::class);
    }

    /** @return BelongsTo<Strategy, $this> */
    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class);
    }

    /** @return BelongsTo<Initiative, $this> */
    public function initiative(): BelongsTo
    {
        return $this->belongsTo(Initiative::class);
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    private function validateScope(): void
    {
        $enterpriseExists = Enterprise::query()->whereKey($this->enterprise_id)->exists();

        if (! $enterpriseExists) {
            return;
        }

        $contexts = [
            [$this->objective_id, fn (int $id): mixed => Objective::query()->whereKey($id)->value('enterprise_id')],
            [$this->strategy_id, fn (int $id): mixed => $this->enterpriseIdThroughStrategy($id)],
            [$this->initiative_id, fn (int $id): mixed => $this->enterpriseIdThroughInitiative($id)],
            [$this->project_id, fn (int $id): mixed => Project::query()->whereKey($id)->value('enterprise_id')],
            [$this->task_id, fn (int $id): mixed => Task::query()->whereKey($id)->value('enterprise_id')],
        ];

        if ($this->strategy_id !== null && $this->initiative_id !== null) {
            $initiativeStrategyId = Initiative::query()->whereKey($this->initiative_id)->value('strategy_id');

            if ($initiativeStrategyId !== null && (int) $initiativeStrategyId !== (int) $this->strategy_id) {
                throw new \LogicException('Decision Initiative must belong to its selected Strategy.');
            }
        }

        foreach ($contexts as [$contextId, $enterpriseIdResolver]) {
            if ($contextId === null) {
                continue;
            }

            $contextEnterpriseId = $enterpriseIdResolver((int) $contextId);

            if ($contextEnterpriseId !== null && (int) $contextEnterpriseId !== (int) $this->enterprise_id) {
                throw new \LogicException('Decision context must belong to its enterprise.');
            }
        }
    }

    private function enterpriseIdThroughStrategy(int $strategyId): ?int
    {
        $objectiveId = Strategy::query()->whereKey($strategyId)->value('objective_id');

        return $objectiveId === null ? null : $this->enterpriseIdThroughObjective((int) $objectiveId);
    }

    private function enterpriseIdThroughInitiative(int $initiativeId): ?int
    {
        $enterpriseId = Initiative::query()->whereKey($initiativeId)->value('enterprise_id');

        return $enterpriseId === null ? null : (int) $enterpriseId;
    }

    private function enterpriseIdThroughObjective(int $objectiveId): ?int
    {
        $enterpriseId = Objective::query()->whereKey($objectiveId)->value('enterprise_id');

        return $enterpriseId === null ? null : (int) $enterpriseId;
    }
}
