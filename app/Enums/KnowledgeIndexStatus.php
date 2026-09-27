<?php

namespace App\Enums;

enum KnowledgeIndexStatus: string
{
    case PENDING = 'pending';
    case INDEXED = 'indexed';
    case STALE = 'stale';
    case FAILED = 'failed';
    case REMOVED = 'removed';
}