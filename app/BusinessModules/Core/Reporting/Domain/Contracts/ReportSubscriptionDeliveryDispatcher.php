<?php
declare(strict_types=1);
namespace App\BusinessModules\Core\Reporting\Domain\Contracts;
interface ReportSubscriptionDeliveryDispatcher { public function dispatch(string $deliveryId, int $delaySeconds): void; }
