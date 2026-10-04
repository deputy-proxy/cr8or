<?php

namespace App\Enums;

enum CapabilityExecutionMode: string
{
    case HUMAN = 'human';
    case AGENT = 'agent';
    case WORKFLOW = 'workflow';
}