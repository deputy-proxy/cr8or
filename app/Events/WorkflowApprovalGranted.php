<?php

namespace App\Events;

final readonly class WorkflowApprovalGranted extends DomainEvent
{
    public const EVENT_TYPE = 'workflow.approval.granted';
}
