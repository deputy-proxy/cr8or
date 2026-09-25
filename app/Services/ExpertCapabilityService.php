<?php

namespace App\Services;

use App\Experts\Expert;
use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

final class ExpertCapabilityService
{
    public function __construct(
        private readonly McpContextAssembler $contextAssembler,
    ) {}

    /**
     * @param  array<string, mixed>  $targetContext
     * @return array<string, mixed>
     */
    public function execute(
        User $actor,
        Enterprise $enterprise,
        string $expertSlug,
        string $capability,
        array $targetContext = [],
    ): array {
        Gate::forUser($actor)->authorize('view', $enterprise);

        $descriptor = ExpertDescriptor::query()->where('slug', $expertSlug)->firstOrFail();

        if (! $descriptor->enabled) {
            throw new AuthorizationException("Expert [{$expertSlug}] is disabled.");
        }

        $runtime = app($descriptor->resolveRuntimeClass());

        if (! $runtime instanceof Expert) {
            throw new AuthorizationException("Expert [{$expertSlug}] has an invalid runtime.");
        }

        if (! in_array($capability, $runtime->capabilities(), true)) {
            throw new AuthorizationException(
                "Expert [{$expertSlug}] does not expose capability [{$capability}].",
            );
        }

        $context = $this->contextAssembler->forAgent(
            $actor,
            $enterprise,
            $runtime->requiredContext(),
        );

        return [
            'expert' => [
                'slug' => $descriptor->slug,
                'name' => $runtime->name(),
                'methodology' => $runtime->methodology(),
            ],
            'capability' => $capability,
            'target_context' => $targetContext,
            'analysis' => $runtime->analyze($context),
            'context_categories' => array_keys($context),
        ];
    }
}
