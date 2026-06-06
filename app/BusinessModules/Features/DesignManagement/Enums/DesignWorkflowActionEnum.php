<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Enums;

enum DesignWorkflowActionEnum: string
{
    case SUBMIT_NORM_CONTROL = 'submit_norm_control';
    case RETURN_TO_WORK = 'return_to_work';
    case SUBMIT_CUSTOMER_REVIEW = 'submit_customer_review';
    case APPROVE = 'approve';
    case ISSUE = 'issue';
    case ARCHIVE = 'archive';
}
