<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\WorkItemService;

final class UpdateWorkItem implements Operation
{
    public function __construct(private readonly WorkItemService $workItems) {}

    public function execute(User $actor, array $input): WorkItem
    {
        $workItem = $input['work_item'] instanceof WorkItem
            ? $input['work_item']
            : WorkItem::query()->findOrFail((int) $input['work_item_id']);

        return $this->workItems->update(
            $actor,
            $workItem,
            $input['attributes'] ?? array_intersect_key($input, array_flip(['name', 'description', 'status', 'project_id'])),
        );
    }
}
