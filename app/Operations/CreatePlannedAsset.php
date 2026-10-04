<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Data\CapabilityExecutionContext;
use App\Models\Asset;
use App\Models\Script;
use App\Models\User;
use App\Services\DomainResourceService;

final class CreatePlannedAsset implements Operation
{
    public function __construct(private readonly DomainResourceService $domain) {}

    public function execute(User $actor, array $input): Asset
    {
        $script = isset($input['script']) && $input['script'] instanceof Script
            ? $input['script']
            : Script::query()->findOrFail((int) $input['script_id']);

        return $this->domain->createPlannedAsset(
            $actor,
            $script,
            $input,
            ($input['execution_context'] ?? null) instanceof CapabilityExecutionContext
                ? $input['execution_context']
                : null,
        );
    }
}