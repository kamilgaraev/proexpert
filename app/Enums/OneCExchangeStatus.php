<?php

declare(strict_types=1);

namespace App\Enums;

enum OneCExchangeStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Posted = 'posted';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case RequiresMapping = 'requires_mapping';
    case DeadLetter = 'dead_letter';
}
