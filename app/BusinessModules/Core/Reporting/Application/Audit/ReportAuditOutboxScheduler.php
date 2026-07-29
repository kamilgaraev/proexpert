<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Audit;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportAuditDispatcher;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportAuditIntentStore;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

final readonly class ReportAuditOutboxScheduler
{
    public function __construct(
        private ReportAuditIntentStore $store,
        private ReportAuditDispatcher $dispatcher,
    ) {}

    public function dispatchDue(int $limit, DateTimeImmutable $occurredAt): int
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('report_audit_batch_size_invalid');
        }

        $this->store->reclaimExpired($limit, $occurredAt);
        $ids = $this->store->dueIds($limit, $occurredAt);
        $dispatched = 0;
        foreach ($ids as $intentId) {
            if (! is_string($intentId) || preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/D', $intentId) !== 1) {
                throw new LogicException('report_audit_due_id_invalid');
            }

            $this->dispatcher->dispatch($intentId);
            $dispatched++;
        }

        return $dispatched;
    }
}
