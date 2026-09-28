<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable([
    'organization_id',
    'enterprise_id',
    'policy_key',
    'capability',
    'stages',
    'expires_in_minutes',
    'allow_self_approval',
    'escalation_roles',
    'enabled',
])]
class ApprovalPolicy extends Model
{
    protected function casts(): array
    {
        return [
            'stages' => 'array',
            'escalation_roles' => 'array',
            'allow_self_approval' => 'boolean',
            'enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ApprovalPolicy $policy): void {
            if ($policy->enterprise_id !== null) {
                $enterprise = Enterprise::query()->find($policy->enterprise_id);

                if ($enterprise === null || (int) $enterprise->organization_id !== (int) $policy->organization_id) {
                    throw new LogicException('Approval policy enterprise must belong to its organization.');
                }
            }

            if (trim((string) $policy->policy_key) === '' || trim((string) $policy->capability) === '') {
                throw new LogicException('Approval policies require a policy key and capability.');
            }

            if ($policy->expires_in_minutes < 1) {
                throw new LogicException('Approval policy expiry must be at least one minute.');
            }

            /** @var mixed $stages */
            $stages = $policy->getAttribute('stages');

            if (! is_array($stages) || $stages === []) {
                throw new LogicException('Approval policy must define at least one approval stage.');
            }

            foreach ($stages as $stage) {
                if (! is_array($stage)
                    || ! is_int($stage['required'] ?? null)
                    || $stage['required'] < 1
                    || ! is_array($stage['roles'] ?? null)
                    || $stage['roles'] === []
                ) {
                    throw new LogicException('Each approval policy stage requires a positive approval count and at least one role.');
                }
            }
        });
    }
}