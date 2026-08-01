<?php
declare(strict_types=1);
namespace App\BusinessModules\Core\Reporting\Domain\Contracts;
use App\BusinessModules\Core\Reporting\Domain\DTO\{ReportExecutionContext,ReportSubscription,ReportSubscriptionDelivery,ReportSubscriptionNotificationReceipt,ReportExport}; use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
interface InAppReportSubscriptionNotifier { public function notify(ReportExecutionContext $context, ReportSubscription $subscription, ReportSubscriptionDelivery $delivery, ReportExport $export, IdempotencyKey $key): ReportSubscriptionNotificationReceipt; }
