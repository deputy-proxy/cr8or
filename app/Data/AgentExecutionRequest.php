<?php

namespace App\Data;

use App\Models\AgentAssignment;
use App\Models\AgentDelegation;
use App\Models\User;
use InvalidArgumentException;

final readonly class AgentExecutionRequest
{
    /**
     * @param  array<string, mixed>  $targetContext
     * @param  list<string>  $expertSlugs
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public User $actor,
        public AgentAssignment $assignment,
        public string $prompt,
        public array $targetContext = [],
        public array $expertSlugs = [],
        public array $options = [],
        public ?string $correlationId = null,
        public ?AgentDelegation $delegation = null,
    ) {
        if (trim($this->prompt) === '') {
            throw new InvalidArgumentException('An Agent execution prompt is required.');
        }

        foreach ($this->expertSlugs as $slug) {
            if (trim($slug) === '') {
                throw new InvalidArgumentException('Agent execution Expert identifiers must be non-empty strings.');
            }
        }
    }
}