<?php

namespace App\Enums\SiteRequest;

use App\Traits\Enums\HasValues;

enum SiteRequestPriorityEnum: string
{
    use HasValues;

    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
} 