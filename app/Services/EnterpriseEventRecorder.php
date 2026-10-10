<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class EnterpriseEventRecorder
{
    /** @var list<string> */
    private const WEBSITE_PAYLOAD_KEYS = [
        'page_path', 'page_title', 'element_id', 'form_id',
        'campaign_source', 'campaign_medium', 'campaign_name',
    ];

    /** @param array<string, mixed> $payload */
    public function record(
        int $organizationId,
        int $enterpriseId,
        string $source,
        string $eventType,
        string $description,
        Carbon $occurredAt,
        array $payload,
        ?string $sourceEventId = null,
        ?int $actorId = null,
        ?string $subjectType = null,
        int|string|null $subjectId = null,
        ?string $correlationId = null,
        ?string $causationId = null,
        ?Carbon $receivedAt = null,
    ): Event {
        $payload = $source === Event::SOURCE_GTM
            ? $this->websitePayload($payload)
            : $this->boundedPayload($payload);

        return Event::query()->firstOrCreate(
            [
                'organization_id' => $organizationId,
                'source' => $source,
                'source_event_id' => $sourceEventId ?: (string) Str::uuid(),
            ],
            [
                'enterprise_id' => $enterpriseId,
                'event_type' => Str::limit($eventType, 100, ''),
                'description' => Str::limit(strip_tags($description), 500, ''),
                'occurred_at' => $occurredAt,
                'payload' => $payload,
                'actor_id' => $actorId,
                'subject_type' => $subjectType === null ? null : Str::limit($subjectType, 100, ''),
                'subject_id' => $subjectId === null ? null : Str::limit((string) $subjectId, 100, ''),
                'correlation_id' => $correlationId === null ? null : Str::limit($correlationId, 100, ''),
                'causation_id' => $causationId === null ? null : Str::limit($causationId, 100, ''),
                'received_at' => $receivedAt ?? now(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function websitePayload(array $payload): array
    {
        $safe = [];
        foreach (self::WEBSITE_PAYLOAD_KEYS as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value)) {
                $safe[$key] = mb_substr(trim(strip_tags($value)), 0, match ($key) {
                    'page_path' => 512,
                    'page_title' => 200,
                    default => 100,
                });
            }
        }

        return $safe;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function boundedPayload(array $payload): array
    {
        $safe = PlatformEventSanitizer::sanitize($payload);
        $json = json_encode($safe);
        if (! is_string($json) || strlen($json) > 4096) {
            return ['payload_truncated' => true];
        }

        return $safe;
    }
}
