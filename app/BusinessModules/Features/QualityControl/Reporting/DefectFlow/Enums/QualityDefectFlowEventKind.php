<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums;

enum QualityDefectFlowEventKind: string
{
    case CREATED = 'created';
    case ASSIGNED = 'assigned';
    case STARTED = 'started';
    case SUBMITTED_FOR_REVIEW = 'submitted_for_review';
    case VERIFIED_RESOLVED = 'verified_resolved';
    case RETURNED_FOR_REWORK = 'returned_for_rework';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';
}
