<?php

use App\Enums\DependencyType;
use App\Models\Dependency;
use App\Models\Enterprise;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\WorkItem;
use InvalidArgumentException;

function dependencyEnterprise(): Enterprise
{
    return Enterprise::factory()->create();
}

function dependencyEndpoints(Enterprise $enterprise): array
{
    return [
        Task::factory()->create(['enterprise_id' => $enterprise->id]),
        WorkItem::factory()->create(['enterprise_id' => $enterprise->id]),
    ];
}

it('creates valid Work dependencies with canonical blocks semantics', function () {
    $enterprise = dependencyEnterprise();
    [$predecessor, $successor] = dependencyEndpoints($enterprise);

    $dependency = Dependency::factory()->create([
        'enterprise_id' => $enterprise->id,
        'predecessor_type' => $predecessor->getMorphClass(),
        'predecessor_id' => $predecessor->id,
        'successor_type' => $successor->getMorphClass(),
        'successor_id' => $successor->id,
    ]);

    expect($dependency->type)->toBe(DependencyType::Blocks)
        ->and($dependency->predecessor->is($predecessor))->toBeTrue()
        ->and($dependency->successor->is($successor))->toBeTrue();
});

it('accepts Project, Task, WorkItem, and Milestone endpoints', function () {
    $enterprise = dependencyEnterprise();
    $project = Project::factory()->create(['enterprise_id' => $enterprise->id]);
    $task = Task::factory()->forProject($project)->create();
    $workItem = WorkItem::factory()->create(['enterprise_id' => $enterprise->id, 'project_id' => $project->id]);
    $milestone = Milestone::factory()->forProject($project)->create();

    foreach ([
        [$project, $task],
        [$task, $workItem],
        [$workItem, $milestone],
    ] as [$predecessor, $successor]) {
        Dependency::factory()->create([
            'enterprise_id' => $enterprise->id,
            'project_id' => $predecessor instanceof Project ? null : $project->id,
            'predecessor_type' => $predecessor->getMorphClass(),
            'predecessor_id' => $predecessor->id,
            'successor_type' => $successor->getMorphClass(),
            'successor_id' => $successor->id,
        ]);
    }

    expect(Dependency::query()->count())->toBe(3);
});

it('rejects unsupported endpoint types', function () {
    $enterprise = dependencyEnterprise();
    [$predecessor, $successor] = dependencyEndpoints($enterprise);

    Dependency::factory()->create([
        'enterprise_id' => $enterprise->id,
        'predecessor_type' => 'App\\Models\\Workflow',
        'predecessor_id' => 1,
        'successor_type' => $successor->getMorphClass(),
        'successor_id' => $successor->id,
    ]);
})->throws(InvalidArgumentException::class, 'Unsupported Work dependency endpoint type.');

it('rejects missing endpoints and cross-enterprise endpoints', function () {
    $enterprise = dependencyEnterprise();
    $otherEnterprise = dependencyEnterprise();
    $task = Task::factory()->create(['enterprise_id' => $enterprise->id]);
    $otherTask = Task::factory()->create(['enterprise_id' => $otherEnterprise->id]);

    expect(fn () => Dependency::factory()->create([
        'enterprise_id' => $enterprise->id,
        'predecessor_type' => Task::class,
        'predecessor_id' => 999999,
        'successor_type' => Task::class,
        'successor_id' => $task->id,
    ]))->toThrow(InvalidArgumentException::class, 'The predecessor endpoint does not exist.');

    expect(fn () => Dependency::factory()->create([
        'enterprise_id' => $enterprise->id,
        'predecessor_type' => Task::class,
        'predecessor_id' => $task->id,
        'successor_type' => Task::class,
        'successor_id' => $otherTask->id,
    ]))->toThrow(InvalidArgumentException::class, 'Dependency endpoints must belong to the dependency Enterprise.');
});

it('enforces project consistency without inferring project ownership', function () {
    $enterprise = dependencyEnterprise();
    $project = Project::factory()->create(['enterprise_id' => $enterprise->id]);
    $otherProject = Project::factory()->create(['enterprise_id' => $enterprise->id]);
    $task = Task::factory()->forProject($project)->create();
    $otherTask = Task::factory()->forProject($otherProject)->create();

    expect(fn () => Dependency::factory()->create([
        'enterprise_id' => $enterprise->id,
        'project_id' => $project->id,
        'predecessor_type' => Task::class,
        'predecessor_id' => $task->id,
        'successor_type' => Task::class,
        'successor_id' => $otherTask->id,
    ]))->toThrow(InvalidArgumentException::class, 'Project-scoped dependencies require both endpoints to belong to the selected Project.');

    expect(fn () => Dependency::factory()->create([
        'enterprise_id' => $enterprise->id,
        'project_id' => 999999,
        'predecessor_type' => Task::class,
        'predecessor_id' => $task->id,
        'successor_type' => Task::class,
        'successor_id' => $task->id,
    ]))->toThrow(InvalidArgumentException::class, 'The dependency project must belong to the dependency Enterprise.');
});

it('rejects self dependencies and duplicate dependencies', function () {
    $enterprise = dependencyEnterprise();
    [$predecessor, $successor] = dependencyEndpoints($enterprise);

    expect(fn () => Dependency::factory()->create([
        'enterprise_id' => $enterprise->id,
        'predecessor_type' => $predecessor->getMorphClass(),
        'predecessor_id' => $predecessor->id,
        'successor_type' => $predecessor->getMorphClass(),
        'successor_id' => $predecessor->id,
    ]))->toThrow(InvalidArgumentException::class, 'A dependency cannot point to the same record.');

    Dependency::factory()->create([
        'enterprise_id' => $enterprise->id,
        'predecessor_type' => $predecessor->getMorphClass(),
        'predecessor_id' => $predecessor->id,
        'successor_type' => $successor->getMorphClass(),
        'successor_id' => $successor->id,
    ]);

    expect(fn () => Dependency::factory()->create([
        'enterprise_id' => $enterprise->id,
        'predecessor_type' => $predecessor->getMorphClass(),
        'predecessor_id' => $predecessor->id,
        'successor_type' => $successor->getMorphClass(),
        'successor_id' => $successor->id,
    ]))->toThrow(InvalidArgumentException::class, 'The dependency already exists.');
});

it('rejects direct and indirect blocking cycles', function () {
    $enterprise = dependencyEnterprise();
    $a = Task::factory()->create(['enterprise_id' => $enterprise->id]);
    $b = Task::factory()->create(['enterprise_id' => $enterprise->id]);
    $c = Task::factory()->create(['enterprise_id' => $enterprise->id]);

    Dependency::factory()->create([
        'enterprise_id' => $enterprise->id,
        'predecessor_type' => Task::class,
        'predecessor_id' => $a->id,
        'successor_type' => Task::class,
        'successor_id' => $b->id,
    ]);

    Dependency::factory()->create([
        'enterprise_id' => $enterprise->id,
        'predecessor_type' => Task::class,
        'predecessor_id' => $b->id,
        'successor_type' => Task::class,
        'successor_id' => $c->id,
    ]);

    expect(fn () => Dependency::factory()->create([
        'enterprise_id' => $enterprise->id,
        'predecessor_type' => Task::class,
        'predecessor_id' => $c->id,
        'successor_type' => Task::class,
        'successor_id' => $a->id,
    ]))->toThrow(InvalidArgumentException::class, 'The dependency would create a cycle');

    expect(fn () => Dependency::factory()->create([
        'enterprise_id' => $enterprise->id,
        'predecessor_type' => Task::class,
        'predecessor_id' => $b->id,
        'successor_type' => Task::class,
        'successor_id' => $a->id,
    ]))->toThrow(InvalidArgumentException::class, 'The dependency would create a cycle');
});

it('allows separate acyclic graphs in different enterprises', function () {
    $first = dependencyEnterprise();
    $second = dependencyEnterprise();
    [$a, $b] = dependencyEndpoints($first);
    [$c, $d] = dependencyEndpoints($second);

    Dependency::factory()->create([
        'enterprise_id' => $first->id,
        'predecessor_type' => $a->getMorphClass(),
        'predecessor_id' => $a->id,
        'successor_type' => $b->getMorphClass(),
        'successor_id' => $b->id,
    ]);

    Dependency::factory()->create([
        'enterprise_id' => $second->id,
        'predecessor_type' => $c->getMorphClass(),
        'predecessor_id' => $c->id,
        'successor_type' => $d->getMorphClass(),
        'successor_id' => $d->id,
    ]);

    expect(Dependency::query()->count())->toBe(2);
});
