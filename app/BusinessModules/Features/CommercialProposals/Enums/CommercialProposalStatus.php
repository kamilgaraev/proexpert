<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals\Enums;

enum CommercialProposalStatus: string
{
    case DRAFT = 'draft';
    case INTERNAL_REVIEW = 'internal_review';
    case APPROVED = 'approved';
    case SENT = 'sent';
    case CUSTOMER_REVIEW = 'customer_review';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case EXPIRED = 'expired';
    case CANCELLED = 'cancelled';

    public function labelKey(): string
    {
        return "commercial_proposals.statuses.{$this->value}";
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::ACCEPTED, self::REJECTED, self::EXPIRED, self::CANCELLED], true);
    }

    /**
     * @return list<string>
     */
    public function availableActions(): array
    {
        return match ($this) {
            self::DRAFT => ['update', 'create_version', 'request_approval', 'archive'],
            self::INTERNAL_REVIEW => ['approve', 'reject', 'archive'],
            self::APPROVED => ['send', 'export', 'create_version', 'archive'],
            self::SENT, self::CUSTOMER_REVIEW => ['record_result', 'export', 'create_version', 'archive'],
            self::ACCEPTED, self::REJECTED, self::EXPIRED, self::CANCELLED => ['export', 'create_version'],
        };
    }
}
