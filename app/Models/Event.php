<?php

namespace App\Models;

use App\Services\PlatformEventSanitizer;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

#[Fillable(['organization_id', 'enterprise_id', 'source', 'event_type', 'description', 'occurred_at', 'payload', 'source_event_id', 'actor_id', 'subject_type', 'subject_id', 'correlation_id', 'causation_id', 'received_at'])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    public const SOURCE_CR8OR = 'cr8or';

    public const SOURCE_GTM = 'gtm';

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime', 'payload' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $event): void {
            if (! in_array($event->source, [self::SOURCE_CR8OR, self::SOURCE_GTM], true)) {
                throw new LogicException('Unsupported Enterprise activity source.');
            }

            $enterprise = Enterprise::query()->find($event->enterprise_id);
            if ($enterprise === null || (int) $enterprise->organization_id !== (int) $event->organization_id) {
                throw new LogicException('Enterprise activity must reference an Enterprise in its organization.');
            }

            $event->source_event_id = trim((string) $event->source_event_id) !== '' ? $event->source_event_id : (string) Str::uuid();
            /** @var mixed $rawPayload */
            $rawPayload = $event->getAttribute('payload');
            $safePayload = PlatformEventSanitizer::sanitize(is_array($rawPayload) ? $rawPayload : []);
            $encodedPayload = json_encode($safePayload);
            if (! is_string($encodedPayload) || strlen($encodedPayload) > 4096) {
                throw new LogicException('Enterprise activity payload exceeds the 4 KiB limit.');
            }
            $event->setAttribute('payload', $safePayload);
        });

        static::updating(function (): never {
            throw new LogicException('Enterprise activity events are immutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('Enterprise activity events are immutable.');
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
