<?php

namespace App\Policies\Concerns;

use App\Models\Enterprise;
use App\Models\Organization;
use App\Models\User;
use App\Policies\Support\RecordOwnership;
use Illuminate\Database\Eloquent\Model;

trait HasExplicitCrudContract
{
    public function viewAny(User $user): bool
    {
        return $user->memberships()->exists();
    }

    public function view(User $user, object $record): bool
    {
        return $record instanceof Model && app(RecordOwnership::class)->allows($user, $record);
    }

    public function create(User $user): bool
    {
        return $user->memberships()->exists();
    }

    public function createForRecord(User $user, Model $record): bool
    {
        return app(RecordOwnership::class)->allows($user, $record);
    }

    public function createForEnterprise(User $user, Enterprise $enterprise): bool
    {
        return $user->memberships()->where('organization_id', $enterprise->organization_id)->exists();
    }

    public function createForOrganization(User $user, Organization $organization): bool
    {
        return $user->memberships()->where('organization_id', $organization->getKey())->exists();
    }

    public function update(User $user, object $record): bool
    {
        return $record instanceof Model && app(RecordOwnership::class)->allows($user, $record);
    }

    public function updateForRecord(User $user, Model $record): bool
    {
        return app(RecordOwnership::class)->allows($user, $record);
    }

    public function delete(User $user, object $record): bool
    {
        return $record instanceof Model && app(RecordOwnership::class)->allows($user, $record);
    }
}
