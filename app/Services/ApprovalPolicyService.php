<?php

namespace App\Services;

use App\Enums\MembershipRole;
use App\Models\AgentAssignment;
use App\Models\ApprovalPolicy;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class ApprovalPolicyService
{
    /** @return array{policy_key:string, stages:list<array{required:int, roles:list<string>}>, expires_in_minutes:int, allow_self_approval:bool, escalation_roles:list<string>} */
    public function snapshotFor(AgentAssignment $assignment, string $capability): array
    {
        $policy = ApprovalPolicy::query()
            ->where('organization_id', $assignment->organization_id)
            ->where('capability', $capability)
            ->where('enabled', true)
            ->where(function ($query) use ($assignment): void {
                $query->where('enterprise_id', $assignment->enterprise_id)
                    ->orWhereNull('enterprise_id');
            })
            ->orderByRaw('CASE WHEN enterprise_id IS NULL THEN 1 ELSE 0 END')
            ->first();

        if ($policy === null) {
            return $this->legacySnapshot();
        }

        $rawStages = $policy->getRawOriginal('stages');
        $rawEscalationRoles = $policy->getRawOriginal('escalation_roles');
        $stages = is_string($rawStages) ? json_decode($rawStages, true) : [];
        $escalationRoles = is_string($rawEscalationRoles) ? json_decode($rawEscalationRoles, true) : [];

        return [
            'policy_key' => $policy->policy_key,
            'stages' => is_array($stages) ? $this->normalizeStages($stages) : [],
            'expires_in_minutes' => (int) $policy->expires_in_minutes,
            'allow_self_approval' => (bool) $policy->allow_self_approval,
            'escalation_roles' => is_array($escalationRoles) ? $this->normalizeRoles($escalationRoles) : [],
        ];
    }

    public function authorizeApprover(ApprovalRequest $request, User $approver): void
    {
        if ($request->status !== ApprovalRequest::STATUS_PENDING) {
            throw new AuthorizationException('Only pending approval requests can be decided.');
        }

        $expiresAt = $request->expiresAt();

        if ($expiresAt !== null && $expiresAt->isPast()) {
            throw new AuthorizationException('The approval request has expired.');
        }

        $snapshot = $this->requestSnapshot($request);
        $stage = $snapshot['stages'][$request->current_stage] ?? null;

        if (! is_array($stage)) {
            throw new AuthorizationException('The approval request has no active approval stage.');
        }

        if (! ($snapshot['allow_self_approval']) && (int) $request->actor_id === (int) $approver->getKey()) {
            throw new AuthorizationException('The requester cannot approve their own action under this approval policy.');
        }

        if (! $this->allowsRole($request, $approver)) {
            throw new AuthorizationException('The approver is not authorized by the approval policy for the active stage.');
        }
    }

    public function allowsRole(ApprovalRequest $request, User $approver): bool
    {
        $role = $approver->memberships()
            ->where('organization_id', $request->organization_id)
            ->value('role');

        if ($role === null) {
            return false;
        }

        $role = $role instanceof MembershipRole ? $role->value : (string) $role;
        $snapshot = $this->requestSnapshot($request);
        $stage = $snapshot['stages'][$request->current_stage] ?? null;

        return is_array($stage) && in_array($role, $this->normalizeRoles($stage['roles']), true);
    }

    /** @return array{policy_key:string, stages:list<array{required:int, roles:list<string>}>, expires_in_minutes:int, allow_self_approval:bool, escalation_roles:list<string>} */
    public function legacySnapshot(): array
    {
        return [
            'policy_key' => 'legacy-owner-admin',
            'stages' => [['required' => 1, 'roles' => [MembershipRole::Owner->value, MembershipRole::Admin->value]]],
            'expires_in_minutes' => 60,
            'allow_self_approval' => false,
            'escalation_roles' => [MembershipRole::Owner->value, MembershipRole::Admin->value],
        ];
    }

    public function requiredApprovals(ApprovalRequest $request): int
    {
        $snapshot = $this->requestSnapshot($request);

        return (int) ($snapshot['stages'][$request->current_stage]['required'] ?? 1);
    }

    public function hasNextStage(ApprovalRequest $request): bool
    {
        $snapshot = $this->requestSnapshot($request);

        return isset($snapshot['stages'][$request->current_stage + 1]);
    }

    /** @return array{policy_key:string, stages:list<array{required:int, roles:list<string>}>, expires_in_minutes:int, allow_self_approval:bool, escalation_roles:list<string>} */
    private function requestSnapshot(ApprovalRequest $request): array
    {
        $raw = $request->getRawOriginal('policy_snapshot');

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                return [
                    'policy_key' => (string) ($decoded['policy_key'] ?? 'legacy-owner-admin'),
                    'stages' => $this->normalizeStages(is_array($decoded['stages'] ?? null) ? $decoded['stages'] : []),
                    'expires_in_minutes' => (int) ($decoded['expires_in_minutes'] ?? 60),
                    'allow_self_approval' => (bool) ($decoded['allow_self_approval'] ?? false),
                    'escalation_roles' => $this->normalizeRoles(is_array($decoded['escalation_roles'] ?? null) ? $decoded['escalation_roles'] : []),
                ];
            }
        }

        return $this->legacySnapshot();
    }

    /**
     * @param  array<int, mixed>  $stages
     * @return list<array{required:int, roles:list<string>}>
     */
    private function normalizeStages(array $stages): array
    {
        $normalized = [];

        foreach ($stages as $stage) {
            if (! is_array($stage)) {
                continue;
            }

            $required = $stage['required'] ?? null;
            $roles = $stage['roles'] ?? null;

            if (! is_int($required) || $required < 1 || ! is_array($roles) || $roles === []) {
                continue;
            }

            $normalized[] = [
                'required' => $required,
                'roles' => $this->normalizeRoles($roles),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, mixed>  $roles
     * @return list<string>
     */
    private function normalizeRoles(array $roles): array
    {
        return array_values(array_map(
            static fn (mixed $role): string => $role instanceof MembershipRole ? $role->value : (string) $role,
            $roles,
        ));
    }
}