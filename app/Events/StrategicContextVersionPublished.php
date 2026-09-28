<?php

namespace App\Events;

final readonly class StrategicContextVersionPublished extends DomainEvent
{
    public const EVENT_TYPE = 'strategic.context.version.published';
}