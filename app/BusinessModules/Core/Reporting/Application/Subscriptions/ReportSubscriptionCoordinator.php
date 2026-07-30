<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Subscriptions;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionDeliveryDispatcher;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionDeliveryStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscription;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionDelivery;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class ReportSubscriptionCoordinator
{
    private Closure $transaction;

    public function __construct(
        private ReportSubscriptionStore $subscriptions,
        private ReportSubscriptionDeliveryStore $deliveries,
        private ReportSubscriptionDeliveryDispatcher $dispatcher,
        ?Closure $transaction = null,
    ) {
        $this->transaction = $transaction ?? static fn (callable $callback): mixed => DB::transaction($callback);
    }

    public function runManual(
        int $organizationId,
        int $ownerId,
        string $subscriptionId,
        IdempotencyKey $idempotencyKey,
        ?DateTimeImmutable $scheduledFor = null,
    ): ReportSubscriptionDelivery {
        $dispatchDeliveryId = null;
        $transaction = $this->transaction;

        $delivery = $transaction(function () use (
            $organizationId,
            $ownerId,
            $subscriptionId,
            $idempotencyKey,
            $scheduledFor,
            &$dispatchDeliveryId,
        ): ReportSubscriptionDelivery {
            $subscription = $this->subscriptions->lock($subscriptionId);
            $this->assertSubscriptionOwner($subscription, $organizationId, $ownerId);

            $triggerKeyHash = new Sha256Hash(hash(
                'sha256',
                'reports-subscription:manual:'.$subscription->id.':'.$idempotencyKey->hash,
            ));
            $manualRequestHash = $this->manualRequestHash($subscription, $triggerKeyHash, $idempotencyKey);

            $newDeliveryId = $this->deliveries->insertManualScheduledOnConflictLocked(
                $subscription,
                $scheduledFor ?? new DateTimeImmutable,
                $triggerKeyHash,
                $manualRequestHash,
                $subscription->executionInputBytes,
                $subscription->executionInputHash,
                $subscription->transitionVersion,
            );

            $delivery = $this->deliveries->lockManualByScope($subscription->id, $triggerKeyHash)
                ?? throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);

            if (
                ! hash_equals((string) $delivery->manualRequestHash?->value, $manualRequestHash->value)
                || ! hash_equals($delivery->executionInputHash->value, $subscription->executionInputHash->value)
                || $delivery->subscriptionVersion !== $subscription->transitionVersion
            ) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_IDEMPOTENCY_CONFLICT);
            }

            $dispatchDeliveryId = $newDeliveryId;

            return $delivery;
        });

        if ($dispatchDeliveryId !== null) {
            $this->dispatcher->dispatch($dispatchDeliveryId, 0);
        }

        return $delivery;
    }

    private function assertSubscriptionOwner(ReportSubscription $subscription, int $organizationId, int $ownerId): void
    {
        if (
            $subscription->organizationId !== $organizationId
            || $subscription->ownerId !== $ownerId
            || $subscription->status !== ReportSubscriptionStatus::ACTIVE
        ) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
        }
    }

    private function manualRequestHash(
        ReportSubscription $subscription,
        Sha256Hash $triggerKeyHash,
        IdempotencyKey $idempotencyKey,
    ): Sha256Hash {
        return new Sha256Hash(hash('sha256', CanonicalJson::encode([
            'execution_input_sha256' => $subscription->executionInputHash->value,
            'idempotency_key_sha256' => $idempotencyKey->hash,
            'subscription_id' => $subscription->id,
            'subscription_version' => $subscription->transitionVersion,
            'trigger_key_sha256' => $triggerKeyHash->value,
        ])));
    }
}
