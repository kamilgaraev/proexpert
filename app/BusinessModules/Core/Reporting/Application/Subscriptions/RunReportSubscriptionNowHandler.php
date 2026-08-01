<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Subscriptions;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionDelivery;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;

final readonly class RunReportSubscriptionNowHandler
{
    public function __construct(private ReportSubscriptionCoordinator $coordinator) {}

    public function handle(ReportExecutionContext $context, string $id, IdempotencyKey $key): ReportSubscriptionDelivery
    {
        return $this->coordinator->runManual($context, $id, $key);
    }
}
