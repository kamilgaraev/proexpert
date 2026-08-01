<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Contracts;

use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardEvidenceEvent;

interface ProcurementAwardEvidenceStore
{
    public function eventsForDecision(int $decisionId): array;

    public function append(ProcurementAwardEvidenceEvent $event): ProcurementAwardEvidenceEvent;
}
