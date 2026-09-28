<?php

namespace App\Services;

use App\Models\Enterprise;
use App\Models\EnterpriseContext;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;

final class EnterpriseContextService
{
    public function __construct(
        private readonly EnterpriseContextAssembler $assembler,
    ) {}

    /**
     * Return the canonical serialized Enterprise context consumed by Agent/Expert runtimes.
     *
     * @return array<string, mixed>
     */
    public function retrieve(User $actor, Enterprise $enterprise): array
    {
        Gate::forUser($actor)->authorize('view', $enterprise);

        $context = $enterprise->context;

        if (! $context instanceof EnterpriseContext) {
            throw (new ModelNotFoundException)->setModel(EnterpriseContext::class, [$enterprise->getKey()]);
        }

        $assembled = $this->assembler->assemble($actor, $enterprise);

        return [
            'enterprise_id' => $enterprise->getKey(),
            'enterprise' => $assembled->section('enterprise')?->data,
            'context' => $assembled->section('enterprise_context')?->data['context'],
            'strategic_context' => $assembled->section('enterprise_context')?->data['strategic_context'],
            'products' => $assembled->section('enterprise_context')?->data['products'],
            'customers' => $assembled->section('enterprise_context')?->data['customers'],
            'partners' => $assembled->section('enterprise_context')?->data['partners'],
            'metadata' => $assembled->metadata()['enterprise_context'] ?? [],
        ];
    }
}