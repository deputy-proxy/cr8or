<?php

namespace App\Contracts;

use App\Models\User;

interface Operation
{
    /** @param array<string, mixed> $input */
    public function execute(User $actor, array $input): mixed;
}
