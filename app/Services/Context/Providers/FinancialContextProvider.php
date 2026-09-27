<?php

namespace App\Services\Context\Providers;

use App\Contracts\AgentContextProvider;
use App\Data\AgentContextSection;
use App\Models\AgentAssignment;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\FinancialReportingService;
use Illuminate\Support\Facades\Gate;

final class FinancialContextProvider implements AgentContextProvider
{
    public function __construct(
        private readonly FinancialReportingService $service,
    ) {}

    public function requirements(): array
    {
        return ['financial'];
    }

    public function provide(User $user, Enterprise $enterprise, array $targetContext = [], ?AgentAssignment $assignment = null): array
    {
        Gate::forUser($user)->authorize('view', $enterprise);

        return [new AgentContextSection(
            name: 'financial',
            data: $this->service->context($enterprise),
            source: self::class,
            scope: [
                'organization_id' => $enterprise->organization_id,
                'enterprise_id' => $enterprise->getKey(),
            ],
            relevance: 'Agent-declared context requirement',
        )];
    }
}