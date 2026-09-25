<?php

namespace App\Capabilities;

use App\Contracts\Operation;
use App\Operations\AnalyzeBusinessContext;
use App\Operations\CreateContentItem;
use App\Operations\CreateStrategy;
use App\Operations\CreateWorkItem;
use App\Operations\DelegateAgent;
use App\Operations\GenerateFinancialReport;
use App\Operations\MarkContentPublicationReady;
use App\Operations\PlanMarketing;
use App\Operations\PublishContent;
use App\Operations\SubmitContentForReview;
use App\Operations\UpdateContentItem;
use App\Operations\UpdateStrategy;
use App\Operations\UpdateWorkItem;
use InvalidArgumentException;

final class CapabilityRegistry
{
    /** @return array<string, CapabilityDefinition> */
    public function all(): array
    {
        return [
            'agent.delegate' => new CapabilityDefinition('agent.delegate', DelegateAgent::class),
            'business.analysis' => new CapabilityDefinition('business.analysis', AnalyzeBusinessContext::class),
            'content.create' => new CapabilityDefinition('content.create', CreateContentItem::class),
            'content.update' => new CapabilityDefinition('content.update', UpdateContentItem::class),
            'content.review' => new CapabilityDefinition('content.review', SubmitContentForReview::class),
            'content.publication_ready' => new CapabilityDefinition('content.publication_ready', MarkContentPublicationReady::class),
            'finance.execute' => new CapabilityDefinition('finance.execute', GenerateFinancialReport::class),
            'marketing.plan' => new CapabilityDefinition('marketing.plan', PlanMarketing::class),
            'publication.publish' => new CapabilityDefinition('publication.publish', PublishContent::class),
            'strategy.create' => new CapabilityDefinition('strategy.create', CreateStrategy::class),
            'strategy.update' => new CapabilityDefinition('strategy.update', UpdateStrategy::class),
            'work.create' => new CapabilityDefinition('work.create', CreateWorkItem::class),
            'work.update' => new CapabilityDefinition('work.update', UpdateWorkItem::class),
        ];
    }

    public function resolve(string $capability): CapabilityDefinition
    {
        $definition = $this->all()[$capability] ?? null;

        if ($definition === null) {
            throw new InvalidArgumentException("Unknown capability [{$capability}].");
        }

        return $definition;
    }

    public function operation(string $capability): Operation
    {
        return app($this->resolve($capability)->operation);
    }
}
