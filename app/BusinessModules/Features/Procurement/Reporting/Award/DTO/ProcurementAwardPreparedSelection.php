<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\DTO;

use InvalidArgumentException;

final readonly class ProcurementAwardPreparedSelection
{
    public function __construct(
        public int $organizationId,
        public ?int $projectId,
        public int $purchaseRequestId,
        public int $supplierRequestId,
        public ?int $supplierRequestVersionId,
        public ?string $supplierRequestVersionHash,
        public ProcurementAwardManifest $manifest,
    ) {
        if ($organizationId < 1
            || ($projectId !== null && $projectId < 1)
            || $purchaseRequestId < 1
            || $supplierRequestId < 1
            || ($supplierRequestVersionId !== null && $supplierRequestVersionId < 1)
            || ($supplierRequestVersionHash !== null
                && preg_match('/^[a-f0-9]{64}$/D', $supplierRequestVersionHash) !== 1)) {
            throw new InvalidArgumentException('procurement_award_prepared_selection_invalid');
        }
    }
}
