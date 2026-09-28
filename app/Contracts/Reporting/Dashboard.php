<?php

namespace App\Contracts\Reporting;

interface Dashboard
{
    public function key(): string;

    public function name(): string;

    /** @return list<string> */
    public function reportTypes(): array;
}