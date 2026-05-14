<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Enums;

enum ExecutiveDocumentStatusEnum: string
{
    case DRAFT = 'draft';
    case PREPARED = 'prepared';
    case UNDER_REVIEW = 'under_review';
    case REMARKS = 'remarks';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case TRANSMITTED = 'transmitted';
    case ARCHIVED = 'archived';

    public function label(): string
    {
        return trans_message("executive_documentation.statuses.{$this->value}");
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'default',
            self::PREPARED, self::UNDER_REVIEW, self::REMARKS => 'warning',
            self::APPROVED, self::TRANSMITTED => 'success',
            self::REJECTED => 'error',
            self::ARCHIVED => 'info',
        };
    }
}
