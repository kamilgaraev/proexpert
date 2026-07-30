<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionDeliveryStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionTrigger;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportSubscriptionDelivery
{
    public function __construct(
        public string $id,
        public int $organizationId,
        public int $ownerId,
        public string $subscriptionId,
        public ReportSubscriptionTrigger $trigger,
        public ?Sha256Hash $triggerKeyHash,
        public ?Sha256Hash $manualRequestHash,
        public DateTimeImmutable $scheduledFor,
        public string $executionInputBytes,
        public Sha256Hash $executionInputHash,
        public int $subscriptionVersion,
        public ReportSubscriptionDeliveryStatus $status,
        public int $attempt,
        public ?string $runId,
        public ?string $exportId,
        public ?string $notificationReceiptId,
        public ?string $safeErrorCode,
        public ?DateTimeImmutable $retryAt,
        public DateTimeImmutable $executionExpiresAt,
        public DateTimeImmutable $retentionDeleteAfter,
    ) {
        if (
            ! hash_equals($executionInputHash->value, hash('sha256', $executionInputBytes))
            || $subscriptionVersion < 1
            || $retentionDeleteAfter <= $executionExpiresAt
        ) {
            throw new InvalidArgumentException('report_subscription_delivery_invalid');
        }
    }

    public function executionInput(): ReportSubscriptionExecutionInput
    {
        return ReportSubscriptionExecutionInput::fromCanonicalBytes($this->executionInputBytes);
    }

    public function runIdempotencyKey(): IdempotencyKey
    {
        return new IdempotencyKey("reports-subscription:{$this->id}:run:v1");
    }

    public function exportIdempotencyKey(): IdempotencyKey
    {
        return new IdempotencyKey("reports-subscription:{$this->id}:export:v1");
    }

    public function notificationIdempotencyKey(): IdempotencyKey
    {
        return new IdempotencyKey("reports-subscription:{$this->id}:notify:v1");
    }
}
