<?php

namespace App\Policies;

use App\Models\Template;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class TemplatePolicy
{
    use HasExplicitCrudContract;

    public function viewAny(User $user): bool
    {
        return $user->memberships()->exists();
    }

    public function view(User $user, Template $template): bool
    {
        if ($template->ownership_scope === Template::SCOPE_PLATFORM) {
            return $user->memberships()->exists();
        }
        if ($template->ownership_scope === Template::SCOPE_USER) {
            return (int) $template->user_id === (int) $user->getKey();
        }

        $organizationId = $template->ownership_scope === Template::SCOPE_ENTERPRISE
            ? $template->enterprise?->organization_id
            : $template->organization_id;

        return $organizationId !== null && $user->memberships()->where('organization_id', $organizationId)->exists();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Template $template): bool
    {
        return false;
    }

    public function delete(User $user, Template $template): bool
    {
        return false;
    }
}