<?php

namespace App\Services\Context\Providers;

use App\Contracts\AgentContextProvider;
use App\Data\AgentContextSection;
use App\Models\AgentAssignment;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\BusinessPerformanceReportingService;

final class ReportingContextProvider implements AgentContextProvider
{
    public function __construct(
        private readonly BusinessPerformanceReportingService $service,
    ) {}

    public function requirements(): array
    {
        return ['reporting'];
    }

    public function provide(User $user, Enterprise $enterprise, array $targetContext = [], ?AgentAssignment $assignment = null): array
    {
        return [new AgentContextSection(
            name: 'reporting',
            data: $this->service->latest($user, $enterprise),
            source: self::class,
            scope: [
                'organization_id' => $enterprise->organization_id,
                'enterprise_id' => $enterprise->getKey(),
            ],
            relevance: 'Agent-declared reporting context requirement',
        )];
    }
}