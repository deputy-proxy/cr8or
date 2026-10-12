<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;

final class CreateTask implements Operation
{
    public function __construct(private readonly TaskService $tasks) {}

    /** @param array<string, mixed> $input */
    public function execute(User $actor, array $input): Task
    {
        $enterprise = ($input['enterprise'] ?? null) instanceof Enterprise
            ? $input['enterprise']
            : Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        return $this->tasks->create($actor, $enterprise, $input);
    }
}
