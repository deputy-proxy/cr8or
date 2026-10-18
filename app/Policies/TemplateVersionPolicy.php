<?php

namespace App\Policies;

use App\Models\TemplateVersion;
use App\Models\User;

class TemplateVersionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->memberships()->exists();
    }

    public function view(User $user, TemplateVersion $version): bool
    {
        return $version->template !== null && (new TemplatePolicy)->view($user, $version->template);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, TemplateVersion $version): bool
    {
        return false;
    }

    public function delete(User $user, TemplateVersion $version): bool
    {
        return false;
    }
}