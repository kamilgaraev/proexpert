<?php
declare(strict_types=1);
namespace App\BusinessModules\Core\Reporting\Domain\DTO;
use DateTimeImmutable;
final readonly class ReportSubscriptionNotificationReceipt { public function __construct(public string $id, public DateTimeImmutable $createdAt) {} }
