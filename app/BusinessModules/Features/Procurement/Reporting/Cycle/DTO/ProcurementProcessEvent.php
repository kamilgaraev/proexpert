<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ProcurementProcessEvent
{
    private const CODES = [
        'request_created',
        'request_approved',
        'solicitation_sent',
        'supplier_responded',
        'award_decided',
        'order_sent',
        'first_receipt',
        'fully_received',
        'cancelled',
    ];

    public function __construct(
        public string $code,
        public DateTimeImmutable $occurredAt,
    ) {
        if (! in_array($code, self::CODES, true)) {
            throw new InvalidArgumentException('Unsupported procurement process event code.');
        }
    }
}
