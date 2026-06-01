<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Enums;

enum DesignPackageStatusEnum: string
{
    case DRAFT = 'draft';
    case IN_WORK = 'in_work';
    case UNDER_REVIEW = 'under_review';
    case APPROVED = 'approved';
    case ISSUED = 'issued';
    case ARCHIVED = 'archived';
}
