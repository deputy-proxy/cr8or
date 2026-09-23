<?php

namespace App\Contracts;

use App\Models\IntegrationConnection;

interface CredentialResolver
{
    public function resolveAccessToken(IntegrationConnection $connection): string;
}
