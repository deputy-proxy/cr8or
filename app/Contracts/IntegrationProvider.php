<?php

namespace App\Contracts;

interface IntegrationProvider
{
    public function integrationKey(): string;

    public function providerKey(): string;

    public function supports(string $operation): bool;
}