<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class ApprovalRequestPolicy
{
    use HasExplicitCrudContract;

    /**
     * This record is changed only through its domain lifecycle operation.
     */
    public function update(User $user, ApprovalRequest $record): bool
    {
        return false;
    }

    /**
     * This record is created through its domain operation, not generic Filament CRUD.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * This record is changed only through its domain lifecycle operation.
     */
    public function delete(User $user, ApprovalRequest $record): bool
    {
        return false;
    }

    public function view(User $user, ApprovalRequest $request): bool
    {
        return $this->organizationRole($user, $request) !== null;
    }

    public function approve(User $user, ApprovalRequest $request): bool
    {
        return in_array($this->organizationRole($user, $request), ['owner', 'admin'], true);
    }

    public function reject(User $user, ApprovalRequest $request): bool
    {
        return $this->approve($user, $request);
    }

    public function cancel(User $user, ApprovalRequest $request): bool
    {
        $role = $this->organizationRole($user, $request);

        return $role !== null && (
            (int) $request->actor_id === (int) $user->getKey()
            || in_array($role, ['owner', 'admin'], true)
        );
    }

    private function organizationRole(User $user, ApprovalRequest $request): ?string
    {
        $role = $user->memberships()
            ->where('organization_id', $request->organization_id)
            ->value('role');

        return $role instanceof MembershipRole ? $role->value : ($role === null ? null : (string) $role);
    }
}