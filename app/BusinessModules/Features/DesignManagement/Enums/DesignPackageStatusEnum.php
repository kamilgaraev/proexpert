<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Enums;

enum DesignPackageStatusEnum: string
{
    case DRAFT = 'draft';
    case IN_WORK = 'in_work';
    case READY_FOR_NORM_CONTROL = 'ready_for_norm_control';
    case UNDER_NORM_CONTROL = 'under_norm_control';
    case RETURNED = 'returned';
    case UNDER_CUSTOMER_REVIEW = 'under_customer_review';
    case APPROVED = 'approved';
    case ISSUED = 'issued';
    case ARCHIVED = 'archived';
}
