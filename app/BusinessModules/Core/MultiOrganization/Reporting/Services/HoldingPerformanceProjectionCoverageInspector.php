<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Services;

use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingPerformanceProjectionCoverage;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAcceptedWorkEventVersion;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAllocationFactVersion;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingPaymentTransactionEventVersion;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final readonly class HoldingPerformanceProjectionCoverageInspector
{
    public function __construct(
        private HoldingPerformanceImmutableEventSource $events,
        private AcceptedWorkHoldingFactProducer $acceptedWork,
        private HoldingPaymentEventFactProducer $payments,
    ) {}

    public function inspect(
        int $holdingId,
        array $organizationIds,
        array $projectIds,
        DateTimeInterface $coverageStartedAt,
        DateTimeInterface $asOf,
        DateTimeInterface $recordedCutoff,
        bool $requirePersisted = true,
    ): HoldingPerformanceProjectionCoverage {
        if ($holdingId < 1 || $asOf < $coverageStartedAt || $recordedCutoff < $coverageStartedAt) {
            throw new InvalidArgumentException('holding_performance_period_outside_coverage');
        }
        $acceptedEvents = $this->events->acceptedWorkVersions(
            $organizationIds,
            $projectIds,
            $coverageStartedAt,
            $asOf,
            $recordedCutoff,
        );
        $paymentEvents = $this->events->paymentVersions(
            $organizationIds,
            $projectIds,
            $coverageStartedAt,
            $asOf,
            $recordedCutoff,
        );
        $eligibleActVersionIds = $this->versionIds($acceptedEvents);
        $projectableActVersionIds = $acceptedEvents
            ->filter(fn (mixed $event): bool => $event instanceof HoldingAcceptedWorkEventVersion
                && $this->acceptedWork->canProjectEvent($event, $holdingId))
            ->map(static fn (HoldingAcceptedWorkEventVersion $event): int => (int) $event->getKey())
            ->values()
            ->all();
        $eligiblePaymentVersionIds = $this->versionIds($paymentEvents);
        $projectablePaymentVersionIds = $paymentEvents
            ->filter(fn (mixed $event): bool => $event instanceof HoldingPaymentTransactionEventVersion
                && $this->payments->canProject($event, $holdingId))
            ->map(static fn (HoldingPaymentTransactionEventVersion $event): int => (int) $event->getKey())
            ->values()
            ->all();
        $activeProjectableActVersionIds = $this->versionIdsByActivity(
            $acceptedEvents,
            $projectableActVersionIds,
            true,
        );
        $inactiveProjectableActVersionIds = $this->versionIdsByActivity(
            $acceptedEvents,
            $projectableActVersionIds,
            false,
        );
        $activeProjectablePaymentVersionIds = $this->versionIdsByActivity(
            $paymentEvents,
            $projectablePaymentVersionIds,
            true,
        );
        $inactiveProjectablePaymentVersionIds = $this->versionIdsByActivity(
            $paymentEvents,
            $projectablePaymentVersionIds,
            false,
        );

        $contributingActVersionIds = $requirePersisted
            ? $this->persistedVersionIds(
                $holdingId,
                $organizationIds,
                $projectIds,
                $coverageStartedAt,
                $asOf,
                $recordedCutoff,
                'performance_act',
                'accepted_accrual',
                $activeProjectableActVersionIds,
            )
            : $activeProjectableActVersionIds;
        $contributingPaymentVersionIds = $requirePersisted
            ? $this->persistedVersionIds(
                $holdingId,
                $organizationIds,
                $projectIds,
                $coverageStartedAt,
                $asOf,
                $recordedCutoff,
                'payment_transaction_event',
                'cash',
                $activeProjectablePaymentVersionIds,
            )
            : $activeProjectablePaymentVersionIds;
        $projectedActVersionIds = $this->normalizedIds([
            ...$contributingActVersionIds,
            ...$inactiveProjectableActVersionIds,
        ]);
        $projectedPaymentVersionIds = $this->normalizedIds([
            ...$contributingPaymentVersionIds,
            ...$inactiveProjectablePaymentVersionIds,
        ]);

        $payload = [
            'holding_id' => $holdingId,
            'organization_ids' => $organizationIds,
            'project_ids' => $projectIds,
            'coverage_started_at' => $coverageStartedAt->format(DateTimeInterface::ATOM),
            'as_of' => $asOf->format(DateTimeInterface::ATOM),
            'recorded_cutoff' => $recordedCutoff->format(DateTimeInterface::ATOM),
            'accepted_work_events' => $acceptedEvents
                ->map(static fn (HoldingAcceptedWorkEventVersion $event): array => [
                    'id' => (int) $event->getKey(),
                    'performance_act_id' => (int) $event->performance_act_id,
                    'organization_id' => (int) $event->organization_id,
                    'project_id' => (int) $event->project_id,
                    'active' => (bool) $event->active,
                    'history_complete' => (bool) $event->history_complete,
                    'occurred_at' => $event->occurred_at?->format(DateTimeInterface::ATOM),
                    'recorded_at' => $event->recorded_at?->format(DateTimeInterface::ATOM),
                    'source_hash' => (string) $event->source_hash,
                ])
                ->all(),
            'payment_events' => $paymentEvents
                ->map(static fn (HoldingPaymentTransactionEventVersion $event): array => [
                    'id' => (int) $event->getKey(),
                    'transaction_id' => (int) $event->transaction_id,
                    'organization_id' => (int) $event->organization_id,
                    'project_id' => $event->project_id === null ? null : (int) $event->project_id,
                    'active' => (bool) $event->active,
                    'history_complete' => (bool) $event->history_complete,
                    'recognized_at' => $event->recognized_at?->format(DateTimeInterface::ATOM),
                    'occurred_at' => $event->occurred_at?->format(DateTimeInterface::ATOM),
                    'recorded_at' => $event->recorded_at?->format(DateTimeInterface::ATOM),
                    'source_hash' => (string) $event->source_hash,
                ])
                ->all(),
            'projected_act_version_ids' => $projectedActVersionIds,
            'contributing_act_version_ids' => $contributingActVersionIds,
            'projected_payment_version_ids' => $projectedPaymentVersionIds,
            'contributing_payment_version_ids' => $contributingPaymentVersionIds,
        ];

        return new HoldingPerformanceProjectionCoverage(
            $eligibleActVersionIds,
            $projectedActVersionIds,
            $contributingActVersionIds,
            $eligiblePaymentVersionIds,
            $projectedPaymentVersionIds,
            $contributingPaymentVersionIds,
            hash('sha256', CanonicalJson::encode($payload)),
        );
    }

    private function persistedVersionIds(
        int $holdingId,
        array $organizationIds,
        array $projectIds,
        DateTimeInterface $coverageStartedAt,
        DateTimeInterface $asOf,
        DateTimeInterface $recordedCutoff,
        string $sourceType,
        string $monetaryBasis,
        array $eligibleVersionIds,
    ): array {
        if ($eligibleVersionIds === []) {
            return [];
        }

        return HoldingAllocationFactVersion::query()
            ->where('source_schema_version', HoldingAllocationFactVersion::SOURCE_SCHEMA_VERSION)
            ->where('holding_id', $holdingId)
            ->whereIn('organization_id', $organizationIds)
            ->whereIn('contributor_organization_id', $organizationIds)
            ->whereIn('project_id', $projectIds)
            ->where('source_type', $sourceType)
            ->where('monetary_basis', $monetaryBasis)
            ->whereBetween('business_effective_at', [$coverageStartedAt, $asOf])
            ->where('recorded_at', '<=', $recordedCutoff)
            ->whereIn('source_version', $eligibleVersionIds)
            ->distinct()
            ->orderBy('source_version')
            ->pluck('source_version')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function versionIds(Collection $events): array
    {
        return $events
            ->map(static fn (mixed $event): int => (int) $event->getKey())
            ->values()
            ->all();
    }

    private function versionIdsByActivity(Collection $events, array $candidateIds, bool $active): array
    {
        return $events
            ->filter(static fn (mixed $event): bool => ($event instanceof HoldingAcceptedWorkEventVersion
                || $event instanceof HoldingPaymentTransactionEventVersion)
                && in_array((int) $event->getKey(), $candidateIds, true)
                && (bool) $event->active === $active)
            ->map(static fn (
                HoldingAcceptedWorkEventVersion|HoldingPaymentTransactionEventVersion $event,
            ): int => (int) $event->getKey())
            ->values()
            ->all();
    }

    private function normalizedIds(array $ids): array
    {
        $normalized = array_values(array_unique(array_map('intval', $ids)));
        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }
}
