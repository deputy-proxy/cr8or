<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\User;

final class RecordMemory implements Operation
{
    public function __construct(private readonly CreateMemory $create) {}

    public function execute(User $actor, array $input): mixed
    {
        if (($input['persist'] ?? false) !== true) {
            throw new \InvalidArgumentException('Durable Agent Memory recording requires persist=true.');
        }

        return $this->create->execute($actor, $input);
    }
}