<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Dispatch;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportDispatcher;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportMaterializationDispatcher;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchAggregate;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchIntent;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchTopic;
use LogicException;

final class LaravelReportDispatchIntentPublisher
{
    public function __construct(
        private readonly ReportMaterializationDispatcher $runs,
        private readonly ReportExportDispatcher $exports,
    ) {}

    public function publish(ReportDispatchIntent $intent): void
    {
        if (
            $intent->aggregate === ReportDispatchAggregate::RUN
            && $intent->topic === ReportDispatchTopic::MATERIALIZE_RUN
        ) {
            $this->runs->dispatch($intent->aggregateId);

            return;
        }

        if (
            $intent->aggregate === ReportDispatchAggregate::EXPORT
            && $intent->topic === ReportDispatchTopic::GENERATE_EXPORT
        ) {
            $this->exports->dispatch($intent->aggregateId);

            return;
        }

        throw new LogicException('report_dispatch_topic_mismatch');
    }
}
