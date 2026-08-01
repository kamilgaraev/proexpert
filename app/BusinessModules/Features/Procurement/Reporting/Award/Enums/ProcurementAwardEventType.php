<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Enums;

enum ProcurementAwardEventType: string
{
    case COMPARISON_CAPTURED = 'comparison_captured';
    case AWARD_APPROVED = 'award_approved';
    case AWARD_REJECTED = 'award_rejected';
    case AWARD_COMMITTED = 'award_committed';
    case SELECTION_SUPERSEDED = 'selection_superseded';
}
