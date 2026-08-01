<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums;

enum QualityDefectFlowTerminalReason: string
{
    case CANCELLED_BY_USER = 'cancelled_by_user';
}
