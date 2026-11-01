<?php

namespace App\Policies;

use App\Models\GitHubWebhookDelivery;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class GitHubWebhookDeliveryPolicy
{
    use HasExplicitCrudContract;

    public function viewAny(User $user): bool
    {
        return $user->memberships()->exists();
    }

    public function view(User $user, GitHubWebhookDelivery $delivery): bool
    {
        return $delivery->organization !== null && (new OrganizationPolicy)->view($user, $delivery->organization);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, GitHubWebhookDelivery $delivery): bool
    {
        return false;
    }

    public function delete(User $user, GitHubWebhookDelivery $delivery): bool
    {
        return false;
    }
}
