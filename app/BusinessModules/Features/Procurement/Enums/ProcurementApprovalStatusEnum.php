<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Enums;

enum ProcurementApprovalStatusEnum: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';
}
