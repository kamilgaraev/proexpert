<?php
declare(strict_types=1);
namespace App\BusinessModules\Core\Reporting\Application\Subscriptions;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionExecutionInput; use DateTimeImmutable;
final class ReportSubscriptionPeriodResolver { public function asOf(ReportSubscriptionExecutionInput $input,DateTimeImmutable $scheduledFor):DateTimeImmutable{return $scheduledFor;} }
