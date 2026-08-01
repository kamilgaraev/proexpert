<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Subscriptions;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;

final readonly class DeleteReportSubscriptionHandler
{
    public function __construct(private ReportSubscriptionCoordinator $coordinator) {}

    public function handle(ReportExecutionContext $context, string $id): void
    {
        $this->coordinator->delete($context, $id);
    }
}
