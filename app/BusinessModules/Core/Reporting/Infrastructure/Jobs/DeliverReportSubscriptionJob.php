<?php
declare(strict_types=1);
namespace App\BusinessModules\Core\Reporting\Infrastructure\Jobs;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionDeliveryProcessor; use Illuminate\Bus\Queueable; use Illuminate\Contracts\Queue\ShouldQueue; use Illuminate\Foundation\Bus\Dispatchable; use Illuminate\Queue\InteractsWithQueue; use Illuminate\Queue\SerializesModels;
final class DeliverReportSubscriptionJob implements ShouldQueue { use Dispatchable,InteractsWithQueue,Queueable,SerializesModels; public function __construct(public readonly string $deliveryId) {} public function handle(ReportSubscriptionDeliveryProcessor $processor): void { $processor->process($this->deliveryId); } }
