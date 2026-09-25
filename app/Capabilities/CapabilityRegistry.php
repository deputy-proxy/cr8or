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
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CapabilityRegistry
{
    /** @return array<string, CapabilityDefinition> */
    public function all(): array
    {
        return [
            'agent.delegate' => new CapabilityDefinition('agent.delegate', DelegateAgent::class, 'delegate-agent'),
            'business.analysis' => new CapabilityDefinition('business.analysis', AnalyzeBusinessContext::class, 'analyze-business-context'),
            'finance.report.generate' => new CapabilityDefinition('finance.report.generate', GenerateFinancialReport::class, 'generate-financial-report'),
            'marketing.content.create' => new CapabilityDefinition('marketing.content.create', CreateContentItem::class, 'create-content-item'),
            'marketing.content.update' => new CapabilityDefinition('marketing.content.update', UpdateContentItem::class, 'update-content-item'),
            'marketing.content.review' => new CapabilityDefinition('marketing.content.review', SubmitContentForReview::class, 'submit-content-for-review'),
            'marketing.content.publication-ready' => new CapabilityDefinition('marketing.content.publication-ready', MarkContentPublicationReady::class, 'mark-content-publication-ready'),
            'marketing.plan' => new CapabilityDefinition('marketing.plan', PlanMarketing::class, 'plan-marketing'),
            'publication.publish' => new CapabilityDefinition('publication.publish', PublishContent::class, 'publish-content'),
            'strategy.create' => new CapabilityDefinition('strategy.create', CreateStrategy::class, 'create-strategy'),
            'strategy.update' => new CapabilityDefinition('strategy.update', UpdateStrategy::class, 'update-strategy'),
            'work.item.create' => new CapabilityDefinition('work.item.create', CreateWorkItem::class, 'create-work-item'),
            'work.item.update' => new CapabilityDefinition('work.item.update', UpdateWorkItem::class, 'update-work-item'),
        ];
    }

    public function resolve(string $capability): CapabilityDefinition
    {
        return $this->all()[$capability] ?? throw new InvalidArgumentException("Unknown capability [{$capability}].");
    }

    public function forTool(string $tool): CapabilityDefinition
    {
        if (str_contains($tool, '\\')) {
            $tool = Str::kebab(Str::beforeLast(class_basename($tool), 'Tool'));
        }

        foreach ($this->all() as $definition) {
            if ($definition->tool === $tool) {
                return $definition;
            }
        }

        throw new InvalidArgumentException("Unknown governed MCP Tool [{$tool}].");
    }

    public function operation(string $capability): Operation
    {
        return app($this->resolve($capability)->operation);
    }

    public function operationForTool(string $tool): Operation
    {
        return $this->operation($this->forTool($tool)->key);
    }
}