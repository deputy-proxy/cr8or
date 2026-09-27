<?php

namespace App\Data;

use InvalidArgumentException;

final readonly class AgentContextSection
{
    /**
     * @param  array<string, mixed>  $scope
     */
    public function __construct(
        public string $name,
        public mixed $data,
        public string $source,
        public array $scope,
        public ?string $relevance = null,
    ) {
        if ($this->name === '') {
            throw new InvalidArgumentException('Agent context section name is required.');
        }

        if ($this->source === '') {
            throw new InvalidArgumentException("Agent context section [{$this->name}] requires a source.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'metadata' => [
                'source' => $this->source,
                'scope' => $this->scope,
                'relevance' => $this->relevance,
            ],
        ];
    }
}
