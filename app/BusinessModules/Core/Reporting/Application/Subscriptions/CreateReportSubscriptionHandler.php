<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Subscriptions;

use App\BusinessModules\Core\Reporting\Domain\DTO\CreateReportSubscriptionData;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscription;

final readonly class CreateReportSubscriptionHandler
{
    public function __construct(private ReportSubscriptionCoordinator $coordinator) {}

    public function handle(ReportExecutionContext $context, CreateReportSubscriptionData $data): ReportSubscription
    {
        return $this->coordinator->create($context, $data);
    }
}
