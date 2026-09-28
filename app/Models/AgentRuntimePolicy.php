<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'environment',
    'organization_id',
    'enterprise_id',
    'agent_descriptor_id',
    'expert_descriptor_id',
    'enabled',
    'max_steps',
    'max_retries',
    'timeout_seconds',
    'max_context_bytes',
    'retrieved_knowledge_limit',
    'memory_limit',
    'provider',
    'model',
    'fallback_providers',
])]
class AgentRuntimePolicy extends Model
{
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'fallback_providers' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (AgentRuntimePolicy $policy): void {
            if ($policy->organization_id === null && ($policy->enterprise_id !== null || $policy->agent_descriptor_id !== null || $policy->expert_descriptor_id !== null)) {
                throw new LogicException('Scoped Agent runtime policies require an organization.');
            }

            if ($policy->environment === '') {
                throw new LogicException('Agent runtime policy environment is required.');
            }

            foreach ([
                'max_steps',
                'max_retries',
                'timeout_seconds',
                'max_context_bytes',
                'retrieved_knowledge_limit',
                'memory_limit',
            ] as $field) {
                if ($policy->{$field} !== null && (int) $policy->{$field} < 1) {
                    throw new LogicException("Agent runtime policy {$field} must be a positive integer.");
                }
            }

            foreach ((array) ($policy->fallback_providers ?? []) as $provider) {
                if (! array_key_exists((string) $provider, (array) config('ai.providers', []))) {
                    throw new LogicException("Unknown fallback AI provider [{$provider}].");
                }
            }

            if ($policy->provider !== null && ! array_key_exists($policy->provider, (array) config('ai.providers', []))) {
                throw new LogicException("Unknown AI provider [{$policy->provider}].");
            }

            if ($policy->enterprise_id !== null && Enterprise::query()->whereKey($policy->enterprise_id)->where('organization_id', $policy->organization_id)->doesntExist()) {
                throw new LogicException('Agent runtime policy Enterprise must belong to its organization.');
            }

            if ($policy->agent_descriptor_id !== null && AgentDescriptor::query()->whereKey($policy->agent_descriptor_id)->doesntExist()) {
                throw new LogicException('Agent runtime policy references an unknown Agent descriptor.');
            }

            if ($policy->expert_descriptor_id !== null && ExpertDescriptor::query()->whereKey($policy->expert_descriptor_id)->doesntExist()) {
                throw new LogicException('Agent runtime policy references an unknown Expert descriptor.');
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<AgentDescriptor, $this> */
    public function agentDescriptor(): BelongsTo
    {
        return $this->belongsTo(AgentDescriptor::class);
    }

    /** @return BelongsTo<ExpertDescriptor, $this> */
    public function expertDescriptor(): BelongsTo
    {
        return $this->belongsTo(ExpertDescriptor::class);
    }
}