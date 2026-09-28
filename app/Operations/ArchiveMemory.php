<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\MemoryResourceService;

final class ArchiveMemory implements Operation
{
    public function __construct(private readonly MemoryResourceService $memory) {}

    public function execute(User $actor, array $input): mixed
    {
        $enterprise = $input['enterprise'] ?? Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        return $this->memory->archive($actor, $enterprise, (int) $input['memory_id']);
    }
}