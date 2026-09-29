<?php

namespace App\Services;

use App\Capabilities\CapabilityRegistry;
use App\Data\AgentExecutionRequest;
use App\Experts\CopywritingExpert;
use App\Models\AgentAssignment;
use App\Models\ContentItem;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class ContentGenerationService
{
    public function __construct(
        private readonly AgentExecutionService $executions,
        private readonly AgentCapabilityAuthorizer $capabilities,
        private readonly CapabilityRegistry $registry,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $modelOptions
     */
    public function generate(
        User $actor,
        AgentAssignment $assignment,
        Enterprise $enterprise,
        string $prompt,
        array $attributes,
        array $modelOptions = [],
    ): ContentItem {
        $result = $this->executions->execute(new AgentExecutionRequest(
            actor: $actor,
            assignment: $assignment,
            prompt: $prompt,
            targetContext: ['enterprise_id' => $enterprise->getKey()],
            options: $modelOptions,
        ));

        if (! $this->capabilities->allowsExpertCapability(
            $assignment,
            'copywriting',
            new CopywritingExpert,
            'marketing.content.create',
            $assignment->organization,
            $enterprise,
            $actor,
            null,
            $result->execution,
            ['enterprise_id' => $enterprise->getKey()],
        )) {
            throw new AuthorizationException('The Agent is not authorized for content creation.');
        }

        /** @var ContentItem $item */
        $item = $this->registry->operation('marketing.content.create')->execute($actor, [
            'enterprise' => $enterprise,
            ...$attributes,
            'body' => $result->modelResult->text,
            'agent_execution_id' => $result->execution->getKey(),
            'agent_decision_id' => $result->decision?->getKey(),
        ]);

        return $item;
    }

    /**
     * @param  array<string, mixed>  $modelOptions
     */
    public function revise(
        User $actor,
        AgentAssignment $assignment,
        ContentItem $item,
        string $prompt,
        array $modelOptions = [],
    ): ContentItem {
        $result = $this->executions->execute(new AgentExecutionRequest(
            actor: $actor,
            assignment: $assignment,
            prompt: $prompt,
            targetContext: ['content_item_id' => $item->getKey()],
            options: $modelOptions,
        ));

        if (! $this->capabilities->allowsExpertCapability(
            $assignment,
            'copywriting',
            new CopywritingExpert,
            'marketing.content.update',
            $assignment->organization,
            $item->enterprise,
            $actor,
            null,
            $result->execution,
            ['content_item_id' => $item->getKey()],
        )) {
            throw new AuthorizationException('The Agent is not authorized for content revision.');
        }

        /** @var ContentItem $updated */
        $updated = $this->registry->operation('marketing.content.update')->execute($actor, [
            'content_item' => $item,
            'attributes' => [
                'body' => $result->modelResult->text,
                'agent_execution_id' => $result->execution->getKey(),
                'agent_decision_id' => $result->decision?->getKey(),
            ],
        ]);

        return $updated;
    }
}