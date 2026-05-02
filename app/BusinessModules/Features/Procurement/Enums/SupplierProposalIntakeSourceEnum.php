<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Enums;

enum SupplierProposalIntakeSourceEnum: string
{
    case EMAIL = 'email';
    case PHONE = 'phone';
    case MESSENGER = 'messenger';
    case FILE_UPLOAD = 'file_upload';
    case MANUAL_FROM_PAPER = 'manual_from_paper';
    case OTHER = 'other';
}
