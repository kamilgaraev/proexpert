<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Services;

use App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts\ProcurementProcessEventStore;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts\ProcurementTransactionBoundary;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementProcessTransition;
use LogicException;

final readonly class ProcurementProcessEventRecorder
{
    public function __construct(
        private ProcurementProcessEventStore $store,
        private ProcurementTransactionBoundary $transactions,
    ) {
    }

    public function record(ProcurementProcessTransition $transition): void
    {
        if (! $this->transactions->isActive()) {
            throw new LogicException('procurement_process_event_owner_transaction_required');
        }

        $this->store->append($transition);
    }
}
