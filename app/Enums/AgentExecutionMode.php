<?php

namespace App\Enums;

enum AgentExecutionMode: string
{
    case INTERACTIVE = 'interactive';
    case AUTONOMOUS = 'autonomous';
}