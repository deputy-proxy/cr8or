<?php

namespace App\Data;

use App\Models\User;

final readonly class CommandWebhookIdentity
{
    /**
     * @param  array<int, string>  $capabilities
     */
    public function __construct(
        public string $keyId,
        public User $actor,
        public array $capabilities,
    ) {}

    public function allows(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }
}