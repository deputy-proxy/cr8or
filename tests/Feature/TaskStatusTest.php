<?php

use App\Enums\TaskStatus;
use App\Models\Enterprise;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Support\Facades\DB;

it('defines the canonical task statuses and labels in one place', function () {
    expect(TaskStatus::values())->toBe(['todo', 'in_progress', 'done', 'cancelled'])
        ->and(TaskStatus::options())->toBe([
            'todo' => 'To do',
            'in_progress' => 'In progress',
            'done' => 'Done',
            'cancelled' => 'Cancelled',
        ]);
});

it('casts persisted task statuses to the canonical enum', function () {
    $enterprise = Enterprise::factory()->create();

    $task = Task::factory()->create([
        'enterprise_id' => $enterprise,
        'status' => TaskStatus::InProgress,
    ]);

    expect($task->status)->toBe(TaskStatus::InProgress)
        ->and($task->fresh()->status)->toBe(TaskStatus::InProgress)
        ->and($task->toArray()['status'])->toBe(TaskStatus::InProgress->value);
});

it('rejects unsupported statuses at the Task service boundary', function () {
    $enterprise = Enterprise::factory()->create();
    $actor = User::factory()->create();
    $service = app(TaskService::class);

    expect(fn () => $service->create($actor, $enterprise, [
        'name' => 'Invalid task',
        'status' => 'in_review',
    ]))->toThrow(InvalidArgumentException::class);

    $task = Task::factory()->create(['enterprise_id' => $enterprise]);

    expect(fn () => $service->update($actor, $task, [
        'status' => 'in_review',
    ]))->toThrow(InvalidArgumentException::class);
});

it('normalizes supported legacy task status aliases before enum casting', function () {
    $enterprise = Enterprise::factory()->create();
    $tasks = collect(['planned', 'completed', 'canceled'])->map(
        fn (): Task => Task::factory()->create(['enterprise_id' => $enterprise])
    );

    foreach ($tasks as $index => $task) {
        DB::table('tasks')->where('id', $task->getKey())->update([
            'status' => ['planned', 'completed', 'canceled'][$index],
        ]);
    }

    $migration = require database_path('migrations/2026_11_03_000001_normalize_task_statuses.php');
    $migration->up();

    expect(DB::table('tasks')->where('id', $tasks[0]->getKey())->value('status'))->toBe('todo')
        ->and(DB::table('tasks')->where('id', $tasks[1]->getKey())->value('status'))->toBe('done')
        ->and(DB::table('tasks')->where('id', $tasks[2]->getKey())->value('status'))->toBe('cancelled');
});

it('fails safely instead of silently coercing unknown persisted task statuses', function () {
    $enterprise = Enterprise::factory()->create();
    $task = Task::factory()->create(['enterprise_id' => $enterprise]);
    DB::table('tasks')->where('id', $task->getKey())->update(['status' => 'custom_state']);

    $migration = require database_path('migrations/2026_11_03_000001_normalize_task_statuses.php');

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'Unsupported existing values: custom_state');
});
