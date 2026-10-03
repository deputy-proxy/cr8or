<?php

namespace App\Policies;

use App\Policies\Concerns\HasExplicitCrudContract;

class CommandWebhookDeliveryPolicy
{
    use HasExplicitCrudContract;
}
