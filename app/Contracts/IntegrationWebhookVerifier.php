<?php

namespace App\Contracts;

use Illuminate\Http\Request;

interface IntegrationWebhookVerifier
{
    public function verify(string $provider, Request $request): void;
}