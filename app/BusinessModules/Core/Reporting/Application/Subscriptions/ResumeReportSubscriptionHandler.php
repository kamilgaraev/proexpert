<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Subscriptions;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscription;

final readonly class ResumeReportSubscriptionHandler
{
    public function __construct(private ReportSubscriptionCoordinator $coordinator) {}

    public function handle(ReportExecutionContext $context, string $id): ReportSubscription
    {
        return $this->coordinator->resume($context, $id);
    }
}
