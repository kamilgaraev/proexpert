<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Enums;

enum SupplierProposalDecisionEnum: string
{
    case DRAFT = 'draft';
    case SELECTED = 'selected';
    case APPROVAL_REQUIRED = 'approval_required';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
}
