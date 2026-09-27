<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

abstract readonly class AgentExecutionEvent implements ShouldDispatchAfterCommit
{
    public const VERSION = 1;

    public const VISIBILITY = 'internal';

    /** @var array<string, mixed> */
    public array $provenance;

    /** @var array<string, mixed> */
    public array $data;

    /**
     * @param  array<string, mixed>  $provenance
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public int $executionId,
        public ?int $enterpriseId,
        public ?int $agentAssignmentId,
        public ?string $agentSlug,
        public ?int $actorId,
        public ?string $correlationId,
        array $provenance = [],
        array $data = [],
    ) {
        $this->provenance = self::sanitize($provenance);
        $this->data = self::sanitize($data);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event' => static::class,
            'version' => self::VERSION,
            'visibility' => self::VISIBILITY,
            'execution_id' => $this->executionId,
            'enterprise_id' => $this->enterpriseId,
            'agent_assignment_id' => $this->agentAssignmentId,
            'agent_slug' => $this->agentSlug,
            'actor_id' => $this->actorId,
            'correlation_id' => $this->correlationId,
            'provenance' => $this->provenance,
            'data' => $this->data,
        ];
    }

    private static function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            $safe = [];

            foreach ($value as $key => $item) {
                $key = (string) $key;
                if (preg_match('/(?:chain.?of.?thought|reasoning|analysis|thought|prompt|instruction|model.?output)/i', $key) === 1) {
                    continue;
                }

                $safe[$key] = self::sanitize($item);
            }

            return $safe;
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return is_object($value) && method_exists($value, 'getKey')
            ? ['id' => $value->getKey()]
            : (string) $value;
    }
}