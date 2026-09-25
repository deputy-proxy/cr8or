<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\WorkItemService;

final class CreateWorkItem implements Operation
{
    public function __construct(private readonly WorkItemService $workItems) {}

    public function execute(User $actor, array $input): WorkItem
    {
        $enterprise = $input['enterprise'] instanceof Enterprise
            ? $input['enterprise']
            : Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        return $this->workItems->create($actor, $enterprise, $input);
    }
}
