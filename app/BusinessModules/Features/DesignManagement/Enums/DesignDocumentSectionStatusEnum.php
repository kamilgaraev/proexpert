<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Enums;

enum DesignDocumentSectionStatusEnum: string
{
    case NOT_STARTED = 'not_started';
    case IN_WORK = 'in_work';
    case READY_FOR_CHECK = 'ready_for_check';
    case CHECKED = 'checked';
    case RETURNED = 'returned';
    case ISSUED = 'issued';
}
