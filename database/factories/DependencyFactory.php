<?php

namespace Database\Factories;

use App\Enums\DependencyType;
use App\Models\Dependency;
use App\Models\Enterprise;
use App\Models\Task;
use App\Models\WorkItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Dependency> */
class DependencyFactory extends Factory
{
    protected $model = Dependency::class;

    public function definition(): array
    {
        return [
            'enterprise_id' => Enterprise::factory(),
            'project_id' => null,
            'predecessor_type' => Task::class,
            'predecessor_id' => null,
            'successor_type' => WorkItem::class,
            'successor_id' => null,
            'type' => DependencyType::Blocks,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Dependency $dependency): void {
            if ($dependency->getAttribute('predecessor_id') === null) {
                $predecessor = Task::factory()->create(['enterprise_id' => $dependency->getAttribute('enterprise_id')]);
                $dependency->predecessor_type = $predecessor->getMorphClass();
                $dependency->predecessor_id = $predecessor->getKey();
            }

            if ($dependency->getAttribute('successor_id') === null) {
                $successor = WorkItem::factory()->create(['enterprise_id' => $dependency->getAttribute('enterprise_id')]);
                $dependency->successor_type = $successor->getMorphClass();
                $dependency->successor_id = $successor->getKey();
            }
        });
    }
}
