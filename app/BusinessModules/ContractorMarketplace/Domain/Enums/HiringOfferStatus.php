<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Domain\Enums;

enum HiringOfferStatus: string
{
    case DRAFT = 'draft';
    case SENT = 'sent';
    case VIEWED = 'viewed';
    case ACCEPTED = 'accepted';
    case DECLINED = 'declined';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';

    public function isOpen(): bool
    {
        return in_array($this, [self::SENT, self::VIEWED], true);
    }
}
