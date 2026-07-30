<?php
declare(strict_types=1);
namespace App\BusinessModules\Core\Reporting\Infrastructure\Jobs;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionDeliveryStore; use DateTimeImmutable; use Illuminate\Bus\Queueable; use Illuminate\Contracts\Queue\ShouldQueue; use Illuminate\Foundation\Bus\Dispatchable;
final class ExpireReportSubscriptionExecutionsJob implements ShouldQueue { use Dispatchable,Queueable; public function handle(ReportSubscriptionDeliveryStore $deliveries):void{$deliveries->expireExecutionsDueLocked(new DateTimeImmutable(),100);} }
