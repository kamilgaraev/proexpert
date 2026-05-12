<?php

declare(strict_types=1);

namespace App\Enums;

enum OneCExchangeStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case RequiresMapping = 'requires_mapping';
}
