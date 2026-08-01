<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Subscriptions;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAccessService;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSavedViewStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSchedulingCapabilityRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionDeliveryDispatcher;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionDeliveryStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\CreateReportSubscriptionData;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedView;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscription;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionDelivery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionExecutionInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\UpdateReportSubscriptionData;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionFrequency;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ReportSubscriptionCoordinator
{
    private Closure $transaction;

    public function __construct(
        private ReportSubscriptionStore $subscriptions,
        private ReportSubscriptionDeliveryStore $deliveries,
        private ReportSubscriptionDeliveryDispatcher $dispatcher,
        ?Closure $transaction = null,
        private ?ReportDefinitionRegistry $definitions = null,
        private ?ReportSchedulingCapabilityRegistry $scheduling = null,
        private ?ReportSavedViewStore $savedViews = null,
        private ?ReportAccessService $access = null,
        private ?ReportSubscriptionScheduleCalculator $schedule = null,
        private ?ReportExecutionClock $clock = null,
    ) {
        $this->transaction = $transaction ?? static fn (callable $callback): mixed => DB::transaction($callback);
    }

    public function create(ReportExecutionContext $context, CreateReportSubscriptionData $data): ReportSubscription
    {
        return ($this->transaction)(function () use ($context, $data): ReportSubscription {
            [$view, $definition] = $this->currentSavedView($context, $data->savedViewId);
            $now = $this->now();
            $subscription = $this->subscriptionFromData($context, $view, $definition, $data, $now);

            return $this->subscriptions->create($subscription);
        });
    }

    public function update(
        ReportExecutionContext $context,
        string $subscriptionId,
        UpdateReportSubscriptionData $data,
    ): ReportSubscription {
        return ($this->transaction)(function () use ($context, $subscriptionId, $data): ReportSubscription {
            $subscription = $this->currentSubscription($context, $subscriptionId);
            $values = $this->updatedValues($subscription, $data);
            [$view, $definition] = $this->currentSavedView($context, $values['saved_view_id']);
            $scheduleData = $this->scheduleData($values);
            $nextRun = $subscription->status === ReportSubscriptionStatus::ACTIVE
                ? $this->nextRun($this->subscriptionFromData($context, $view, $definition, $scheduleData, $this->now()), $this->now())
                : null;
            $input = $this->executionInput($view, $definition, $scheduleData);

            return $this->subscriptions->updateLocked($subscription, [
                'saved_view_id' => $view->id,
                'report_code' => $definition->code,
                'frequency' => $scheduleData->frequency->value,
                'weekday' => $scheduleData->weekday,
                'day_of_month' => $scheduleData->dayOfMonth,
                'local_time' => $scheduleData->localTime,
                'timezone' => $scheduleData->timezone->getName(),
                'period_policy_json' => $scheduleData->periodPolicy,
                'format' => $scheduleData->format,
                'next_run_at' => $nextRun,
                'execution_input_bytes' => $input->canonicalBytes(),
                'execution_input_sha256' => $input->digest()->value,
                'definition_sha256' => $definition->definitionHash->value,
                'contract_version' => $definition->contractVersion,
            ]);
        });
    }

    public function delete(ReportExecutionContext $context, string $subscriptionId): void
    {
        ($this->transaction)(function () use ($context, $subscriptionId): void {
            $this->subscriptions->softDeleteLocked($this->currentSubscription($context, $subscriptionId));
        });
    }

    public function pause(ReportExecutionContext $context, string $subscriptionId): ReportSubscription
    {
        return ($this->transaction)(function () use ($context, $subscriptionId): ReportSubscription {
            $subscription = $this->currentSubscription($context, $subscriptionId);
            if ($subscription->status !== ReportSubscriptionStatus::ACTIVE) {
                $this->invalid();
            }

            return $this->subscriptions->updateLocked($subscription, [
                'status' => ReportSubscriptionStatus::PAUSED->value,
                'next_run_at' => null,
            ]);
        });
    }

    public function resume(ReportExecutionContext $context, string $subscriptionId): ReportSubscription
    {
        return ($this->transaction)(function () use ($context, $subscriptionId): ReportSubscription {
            $subscription = $this->currentSubscription($context, $subscriptionId);
            if ($subscription->status !== ReportSubscriptionStatus::PAUSED) {
                $this->invalid();
            }

            return $this->subscriptions->updateLocked($subscription, [
                'status' => ReportSubscriptionStatus::ACTIVE->value,
                'disabled_reason' => null,
                'next_run_at' => $this->nextRun($subscription, $this->now()),
            ]);
        });
    }

    public function runManual(
        ReportExecutionContext $context,
        string $subscriptionId,
        IdempotencyKey $idempotencyKey,
        ?DateTimeImmutable $scheduledFor = null,
    ): ReportSubscriptionDelivery {
        $dispatchDeliveryId = null;

        $delivery = ($this->transaction)(function () use (
            $context,
            $subscriptionId,
            $idempotencyKey,
            $scheduledFor,
            &$dispatchDeliveryId,
        ): ReportSubscriptionDelivery {
            $subscription = $this->currentSubscription($context, $subscriptionId);
            if ($subscription->status !== ReportSubscriptionStatus::ACTIVE) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
            }

            $triggerKeyHash = new Sha256Hash(hash(
                'sha256',
                'reports-subscription:manual:'.$subscription->id.':'.$idempotencyKey->hash,
            ));
            $manualRequestHash = $this->manualRequestHash($subscription, $triggerKeyHash, $idempotencyKey);
            $newDeliveryId = $this->deliveries->insertManualScheduledOnConflictLocked(
                $subscription,
                $scheduledFor ?? $this->now(),
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

    private function currentSubscription(ReportExecutionContext $context, string $subscriptionId): ReportSubscription
    {
        try {
            $subscription = $this->subscriptions->lock($subscriptionId);
        } catch (\Throwable $exception) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND, previous: $exception);
        }

        if (
            $subscription->organizationId !== $context->scope->organizationId
            || $subscription->ownerId !== $context->actor->id
            || $subscription->status === ReportSubscriptionStatus::DELETED
        ) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
        }

        $this->currentSavedView($context, $subscription->savedViewId, $subscription->reportCode);

        return $subscription;
    }

    /** @return array{0:ReportSavedView,1:ReportDefinition} */
    private function currentSavedView(
        ReportExecutionContext $context,
        string $savedViewId,
        ?string $expectedReportCode = null,
    ): array {
        $this->requireLifecycleDependencies();
        $view = $this->savedViews->getVisible($context->scope->organizationId, $context->actor->id, $savedViewId);
        if ($view->status !== 'active' || ($expectedReportCode !== null && $view->reportCode !== $expectedReportCode)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
        }

        $definition = $this->definitions->published($view->reportCode)->payload();
        $this->access->assertOperation($context, $definition, ReportOperation::MANAGE, null);
        $capability = $this->scheduling->published($definition->code);
        if (! $definition->supportsSubscriptions || ! $capability->supportsSubscriptions || ! $capability->reproducibleScheduledSnapshot) {
            $this->invalid();
        }

        return [$view, $definition];
    }

    /** @return array<string, mixed> */
    private function updatedValues(ReportSubscription $subscription, UpdateReportSubscriptionData $data): array
    {
        $values = [
            'saved_view_id' => $subscription->savedViewId,
            'frequency' => $subscription->frequency,
            'weekday' => $subscription->weekday,
            'day_of_month' => $subscription->dayOfMonth,
            'local_time' => $subscription->localTime,
            'timezone' => $subscription->timezone,
            'period_policy' => $subscription->periodPolicy,
            'format' => $subscription->format,
        ];

        return array_replace($values, $data->changes);
    }

    /** @param array<string, mixed> $values */
    private function scheduleData(array $values): CreateReportSubscriptionData
    {
        try {
            $frequency = $values['frequency'] instanceof ReportSubscriptionFrequency
                ? $values['frequency']
                : ReportSubscriptionFrequency::from((string) $values['frequency']);
            $timezone = $values['timezone'] instanceof DateTimeZone
                ? $values['timezone']
                : new DateTimeZone((string) $values['timezone']);

            return new CreateReportSubscriptionData(
                (string) $values['saved_view_id'],
                $frequency,
                $values['weekday'] === null ? null : (int) $values['weekday'],
                $values['day_of_month'] === null ? null : (int) $values['day_of_month'],
                (string) $values['local_time'],
                $timezone,
                is_array($values['period_policy']) ? $values['period_policy'] : [],
                (string) $values['format'],
            );
        } catch (\Throwable $exception) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID, previous: $exception);
        }
    }

    private function subscriptionFromData(
        ReportExecutionContext $context,
        ReportSavedView $view,
        ReportDefinition $definition,
        CreateReportSubscriptionData $data,
        DateTimeImmutable $now,
    ): ReportSubscription {
        $input = $this->executionInput($view, $definition, $data);
        $subscription = new ReportSubscription(
            (string) Str::ulid(),
            $context->scope->organizationId,
            $context->actor->id,
            $view->id,
            $definition->code,
            $data->frequency,
            $data->weekday,
            $data->dayOfMonth,
            $data->localTime,
            $data->timezone,
            $data->periodPolicy,
            $data->format,
            ReportSubscriptionStatus::ACTIVE,
            null,
            0,
            null,
            $input->canonicalBytes(),
            $input->digest(),
            $definition->definitionHash,
            $definition->contractVersion,
            1,
            $now,
            $now,
        );

        return new ReportSubscription(
            $subscription->id,
            $subscription->organizationId,
            $subscription->ownerId,
            $subscription->savedViewId,
            $subscription->reportCode,
            $subscription->frequency,
            $subscription->weekday,
            $subscription->dayOfMonth,
            $subscription->localTime,
            $subscription->timezone,
            $subscription->periodPolicy,
            $subscription->format,
            $subscription->status,
            $subscription->disabledReason,
            $subscription->consecutiveFailures,
            $this->nextRun($subscription, $now),
            $subscription->executionInputBytes,
            $subscription->executionInputHash,
            $subscription->definitionHash,
            $subscription->contractVersion,
            $subscription->transitionVersion,
            $subscription->createdAt,
            $subscription->updatedAt,
        );
    }

    private function executionInput(
        ReportSavedView $view,
        ReportDefinition $definition,
        CreateReportSubscriptionData $data,
    ): ReportSubscriptionExecutionInput {
        if (! in_array($data->format, $definition->formats, true)) {
            $this->invalid();
        }

        return new ReportSubscriptionExecutionInput(
            $definition->code,
            $view->filters,
            $view->comparison,
            'ru',
            $view->id,
            $data->format,
            $view->columns,
            $view->sort,
            $data->timezone,
            $data->periodPolicy,
            $definition->contractVersion,
            $definition->definitionHash,
        );
    }

    private function nextRun(ReportSubscription $subscription, DateTimeImmutable $after): DateTimeImmutable
    {
        $this->requireLifecycleDependencies();

        return $this->schedule->next($subscription, $after);
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function requireLifecycleDependencies(): void
    {
        if (
            $this->definitions === null
            || $this->scheduling === null
            || $this->savedViews === null
            || $this->access === null
            || $this->schedule === null
        ) {
            throw new \LogicException('report_subscription_lifecycle_dependencies_missing');
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

    private function invalid(): never
    {
        throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
    }
}
