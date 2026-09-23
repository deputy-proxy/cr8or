<?php

namespace App\Contracts;

interface MediaStorage
{
    /** @param array<string, mixed> $metadata */
    public function store(string $path, string $contents, array $metadata = []): string;
}
