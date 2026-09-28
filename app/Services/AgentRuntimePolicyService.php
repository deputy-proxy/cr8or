<?php

namespace App\Services;

use App\Enums\MembershipRole;
use App\Models\AgentAssignment;
use App\Models\AgentRuntimePolicy;
use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

final class AgentRuntimePolicyService
{
    /** @param array<string, mixed> $attributes */
    public function upsert(User $actor, array $attributes): AgentRuntimePolicy
    {
        $organizationId = isset($attributes['organization_id']) ? (int) $attributes['organization_id'] : null;
        $enterpriseId = isset($attributes['enterprise_id']) ? (int) $attributes['enterprise_id'] : null;

        if ($enterpriseId !== null) {
            $enterprise = Enterprise::query()->findOrFail($enterpriseId);
            Gate::forUser($actor)->authorize('update', $enterprise);
            $organizationId = $enterprise->organization_id;
        } elseif ($organizationId === null) {
            throw new AuthorizationException('Runtime policy scope requires an organization or Enterprise.');
        } elseif (! $actor->memberships()
            ->where('organization_id', $organizationId)
            ->whereIn('role', [MembershipRole::Owner->value, MembershipRole::Admin->value])
            ->exists()) {
            throw new AuthorizationException('Runtime policy changes require organization owner/admin authority.');
        }

        $attributes['organization_id'] = $organizationId;
        $attributes['environment'] = (string) ($attributes['environment'] ?? config('agent_runtime.environment', app()->environment()));

        $query = AgentRuntimePolicy::query()
            ->where('environment', $attributes['environment'])
            ->where('organization_id', $organizationId);

        foreach (['enterprise_id', 'agent_descriptor_id', 'expert_descriptor_id'] as $field) {
            $value = $attributes[$field] ?? null;
            $value === null ? $query->whereNull($field) : $query->where($field, $value);
        }

        $policy = $query->first() ?? new AgentRuntimePolicy;
        $policy->fill($attributes);
        $policy->save();

        return $policy->refresh();
    }

    /** @return array<string, mixed> */
    public function resolveForAssignment(
        User $actor,
        AgentAssignment $assignment,
        ?ExpertDescriptor $expert = null,
    ): array {
        $assignment->loadMissing(['agentDescriptor', 'enterprise', 'organization']);
        Gate::forUser($actor)->authorize('view', $assignment);

        $enterprise = $assignment->enterprise;

        if (! $enterprise instanceof Enterprise || $enterprise->organization_id !== $assignment->organization_id) {
            throw new AuthorizationException('Runtime policy resolution requires an enterprise-scoped assignment.');
        }

        return $this->resolve(
            organizationId: $assignment->organization_id,
            enterpriseId: $enterprise->getKey(),
            agentDescriptorId: $assignment->agent_descriptor_id,
            expertDescriptorId: $expert?->getKey(),
        );
    }

    /** @return array<string, mixed> */
    public function resolve(
        int $organizationId,
        int $enterpriseId,
        ?int $agentDescriptorId = null,
        ?int $expertDescriptorId = null,
    ): array {
        $environment = (string) config('agent_runtime.environment', app()->environment());
        $defaults = (array) config('agent_runtime.defaults', []);

        $policies = AgentRuntimePolicy::query()
            ->where('environment', $environment)
            ->where(function ($query) use ($organizationId, $enterpriseId, $agentDescriptorId, $expertDescriptorId): void {
                $query
                    ->where(function ($query) use ($organizationId): void {
                        $query->whereNull('organization_id')->orWhere('organization_id', $organizationId);
                    })
                    ->where(function ($query) use ($enterpriseId): void {
                        $query->whereNull('enterprise_id')->orWhere('enterprise_id', $enterpriseId);
                    })
                    ->where(function ($query) use ($agentDescriptorId): void {
                        $query->whereNull('agent_descriptor_id')->orWhere('agent_descriptor_id', $agentDescriptorId);
                    })
                    ->where(function ($query) use ($expertDescriptorId): void {
                        $query->whereNull('expert_descriptor_id')->orWhere('expert_descriptor_id', $expertDescriptorId);
                    });
            })->get()
            ->sortBy(function (AgentRuntimePolicy $policy): int {
                return count(array_filter([
                    $policy->organization_id,
                    $policy->enterprise_id,
                    $policy->agent_descriptor_id,
                    $policy->expert_descriptor_id,
                ], static fn (mixed $value): bool => $value !== null));
            })
            ->values();

        foreach ($policies as $policy) {
            $defaults = array_replace($defaults, array_filter([
                'enabled' => $policy->enabled,
                'max_steps' => $policy->max_steps,
                'max_retries' => $policy->max_retries,
                'timeout_seconds' => $policy->timeout_seconds,
                'max_context_bytes' => $policy->max_context_bytes,
                'retrieved_knowledge_limit' => $policy->retrieved_knowledge_limit,
                'memory_limit' => $policy->memory_limit,
                'provider' => $policy->provider,
                'model' => $policy->model,
                'fallback_providers' => $policy->fallback_providers,
            ], static fn (mixed $value): bool => $value !== null));
        }

        $this->validateResolved($defaults);

        return $defaults + ['environment' => $environment];
    }

    /** @param array<string, mixed> $policy */
    public function assertCanExecute(array $policy): void
    {
        if (($policy['enabled'] ?? false) !== true) {
            throw new AuthorizationException('Agent runtime policy disables execution.');
        }
    }

    /**
     * @param  array<string, mixed>  $policy
     * @param  array<string, mixed>  $context
     */
    public function enforceContextSize(array $policy, array $context): void
    {
        $limit = (int) ($policy['max_context_bytes'] ?? 120000);
        $size = strlen(json_encode($context, JSON_THROW_ON_ERROR));

        if ($size > $limit) {
            throw new AuthorizationException(sprintf(
                'Agent context exceeds the configured runtime limit of %d bytes.',
                $limit,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $policy
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function enforceOptions(array $policy, array $options): array
    {
        $requestedSteps = $options['max_steps'] ?? (int) $policy['max_steps'];
        $requestedRetries = $options['max_retries'] ?? (int) $policy['max_retries'];
        $requestedTimeout = $options['timeout'] ?? (int) $policy['timeout_seconds'];

        foreach ([
            'max_steps' => $requestedSteps,
            'max_retries' => $requestedRetries,
            'timeout' => $requestedTimeout,
        ] as $key => $value) {
            if (! is_int($value) || $value < 1) {
                throw new AuthorizationException("Agent runtime {$key} must be a positive integer.");
            }
        }

        if ($requestedSteps > (int) $policy['max_steps']) {
            throw new AuthorizationException('Requested max_steps exceeds the effective Agent runtime policy.');
        }

        if ($requestedRetries > (int) $policy['max_retries']) {
            throw new AuthorizationException('Requested max_retries exceeds the effective Agent runtime policy.');
        }

        if ($requestedTimeout > (int) $policy['timeout_seconds']) {
            throw new AuthorizationException('Requested timeout exceeds the effective Agent runtime policy.');
        }

        $provider = isset($options['provider']) ? (string) $options['provider'] : (string) $policy['provider'];
        $model = isset($options['model']) ? (string) $options['model'] : ($policy['model'] !== null ? (string) $policy['model'] : null);

        $allowedProviders = array_values(array_unique(array_filter([
            $provider,
            $policy['provider'],
            ...(array) ($policy['fallback_providers'] ?? []),
        ])));

        foreach ($allowedProviders as $candidate) {
            if (! array_key_exists($candidate, (array) config('ai.providers', []))) {
                throw new AuthorizationException("Configured AI provider [{$candidate}] is unavailable.");
            }
        }

        return [
            ...$options,
            'max_steps' => $requestedSteps,
            'max_retries' => $requestedRetries,
            'timeout' => $requestedTimeout,
            'provider' => $provider,
            'model' => $model,
            'fallback_providers' => array_values(array_filter(
                (array) ($policy['fallback_providers'] ?? []),
                fn (string $candidate): bool => $candidate !== $provider,
            )),
        ];
    }

    /** @param array<string, mixed> $policy */
    private function validateResolved(array $policy): void
    {
        foreach ([
            'max_steps' => [1, 100],
            'max_retries' => [1, 20],
            'timeout_seconds' => [1, 3600],
            'max_context_bytes' => [1000, 1000000],
            'retrieved_knowledge_limit' => [1, 100],
            'memory_limit' => [1, 100],
        ] as $key => [$min, $max]) {
            $value = $policy[$key] ?? null;
            if (! is_int($value) || $value < $min || $value > $max) {
                throw new AuthorizationException("Resolved Agent runtime policy [{$key}] is outside safe bounds.");
            }
        }
    }
}