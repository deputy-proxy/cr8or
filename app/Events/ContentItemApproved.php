<?php

namespace App\Events;

final readonly class ContentItemApproved extends DomainEvent
{
    public const EVENT_TYPE = 'marketing.content.approved';
}
