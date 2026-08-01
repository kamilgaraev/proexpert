<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Contracts;

use DateTimeImmutable;

interface ProcurementAwardSelectionSource
{
    public function candidateRows(
        int $organizationId,
        int $supplierRequestId,
        DateTimeImmutable $occurredAt,
    ): array;

    public function supplierRequestIds(
        int $organizationId,
        int $purchaseRequestId,
        DateTimeImmutable $occurredAt,
    ): array;
}
