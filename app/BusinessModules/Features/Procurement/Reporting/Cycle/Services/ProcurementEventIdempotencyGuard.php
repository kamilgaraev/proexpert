<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Services;

use LogicException;

final class ProcurementEventIdempotencyGuard
{
    public function isExactReplay(?string $existingPayloadHash, string $incomingPayloadHash): bool
    {
        if ($existingPayloadHash === null) {
            return false;
        }
        if (! hash_equals($existingPayloadHash, $incomingPayloadHash)) {
            throw new LogicException('procurement_process_event_idempotency_conflict');
        }

        return true;
    }
}
