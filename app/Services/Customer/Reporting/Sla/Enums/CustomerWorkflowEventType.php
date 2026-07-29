<?php

declare(strict_types=1);

namespace App\Services\Customer\Reporting\Sla\Enums;

enum CustomerWorkflowEventType: string
{
    case OPENED = 'opened';
    case COMMENTED = 'commented';
    case STATUS_CHANGED = 'status_changed';
    case RESOLVED = 'resolved';
    case REOPENED = 'reopened';
}
