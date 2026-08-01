<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Subscriptions;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscription;
use App\BusinessModules\Core\Reporting\Domain\DTO\UpdateReportSubscriptionData;

final readonly class UpdateReportSubscriptionHandler
{
    public function __construct(private ReportSubscriptionCoordinator $coordinator) {}

    public function handle(ReportExecutionContext $context, string $id, UpdateReportSubscriptionData $data): ReportSubscription
    {
        return $this->coordinator->update($context, $id, $data);
    }
}
