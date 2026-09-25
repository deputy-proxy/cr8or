<?php

namespace App\Services;

use App\Capabilities\CapabilityRegistry;
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
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $modelOptions
     */
    public function generate(
        User $actor,
        AgentAssignment $assignment,
        Enterprise $enterprise,
        string $prompt,
        array $attributes,
        array $modelOptions = [],
    ): ContentItem {
        $result = $this->executions->execute(
            $actor,
            $assignment,
            $prompt,
            targetContext: ['enterprise_id' => $enterprise->getKey()],
            modelOptions: $modelOptions,
        );

        if (! $this->capabilities->allows(
            $assignment,
            'content.create',
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
        $item = $this->registry->operation('content.create')->execute($actor, [
            'enterprise' => $enterprise,
            ...$attributes,
            'body' => $result->modelResult->text,
            'agent_execution_id' => $result->execution->getKey(),
            'agent_decision_id' => $result->decision?->getKey(),
        ]);

        return $item;
    }

    /** @param array<string, mixed> $modelOptions */
    public function revise(
        User $actor,
        AgentAssignment $assignment,
        ContentItem $item,
        string $prompt,
        array $modelOptions = [],
    ): ContentItem {
        $result = $this->executions->execute(
            $actor,
            $assignment,
            $prompt,
            targetContext: ['content_item_id' => $item->getKey()],
            modelOptions: $modelOptions,
        );

        if (! $this->capabilities->allows(
            $assignment,
            'content.update',
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
        $updated = $this->registry->operation('content.update')->execute($actor, [
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
