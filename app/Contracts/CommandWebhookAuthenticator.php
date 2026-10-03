<?php

namespace App\Contracts;

use App\Data\CommandWebhookIdentity;
use Illuminate\Http\Request;

interface CommandWebhookAuthenticator
{
    public function authenticate(Request $request): CommandWebhookIdentity;
}