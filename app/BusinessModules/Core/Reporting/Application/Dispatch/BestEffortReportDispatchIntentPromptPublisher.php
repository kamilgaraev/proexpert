<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Dispatch;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportDispatchIntentPromptPublisher;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class BestEffortReportDispatchIntentPromptPublisher implements ReportDispatchIntentPromptPublisher
{
    public function __construct(
        private ReportDispatchIntentPublisher $publisher,
        private LoggerInterface $logger,
        private int $batchSize,
    ) {
    }

    public function publishPending(): void
    {
        try {
            $this->publisher->publishBatch(
                $this->batchSize,
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );
        } catch (Throwable $exception) {
            $this->logger->warning('Immediate report dispatch intent publication failed; scheduled reconciliation remains active.', [
                'exception_class' => $exception::class,
            ]);
        }
    }
}
