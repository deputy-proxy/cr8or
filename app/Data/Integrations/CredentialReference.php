<?php

namespace App\Data\Integrations;

use InvalidArgumentException;

final readonly class CredentialReference
{
    public function __construct(public string $value)
    {
        if (trim($value) === '' || preg_match('/(?:token|secret|password|key)=/i', $value) === 1) {
            throw new InvalidArgumentException('Credential references must identify managed credentials without containing credential material.');
        }
    }

    public function value(): string
    {
        return $this->value;
    }
}