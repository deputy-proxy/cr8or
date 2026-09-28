<?php

namespace App\Contracts;

interface PlatformEvent
{
    public function category(): string;

    public function eventId(): string;

    public function version(): int;

    /** @return array<string, mixed> */
    public function metadata(): array;

    /** @return array<string, mixed> */
    public function payload(): array;
}