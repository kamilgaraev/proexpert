<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Services;

use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Contracts\QualityDefectFlowStore;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Contracts\QualityDefectFlowTransactionBoundary;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DTO\QualityDefectFlowEvent;
use LogicException;

final readonly class QualityDefectFlowEventRecorder
{
    public function __construct(
        private QualityDefectFlowStore $store,
        private QualityDefectFlowTransactionBoundary $transactions,
    ) {}

    public function record(QualityDefectFlowEvent $event): string
    {
        if (! $this->transactions->isActive()) {
            throw new LogicException('quality_defect_flow_owner_transaction_required');
        }

        return $this->store->append($event);
    }
}
