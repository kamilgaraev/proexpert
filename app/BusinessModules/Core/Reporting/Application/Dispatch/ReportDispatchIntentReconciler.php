<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Dispatch;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportDispatchIntentStore;
use DateTimeImmutable;
use InvalidArgumentException;

final class ReportDispatchIntentReconciler
{
    public function __construct(
        private readonly ReportDispatchIntentStore $store,
        private readonly ReportDispatchIntentPublisher $publisher,
    ) {}

    public function reconcile(int $limit, DateTimeImmutable $occurredAt): ReportDispatchPublishSummary
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('report_dispatch_batch_size_invalid');
        }

        $this->store->reclaimExpiredLeases($limit, $occurredAt);

        return $this->publisher->publishBatch($limit, $occurredAt);
    }
}
