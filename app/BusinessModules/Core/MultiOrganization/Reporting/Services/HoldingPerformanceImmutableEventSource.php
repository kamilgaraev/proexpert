<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Services;

use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAcceptedWorkEventVersion;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingPaymentEventCoverageCheckpoint;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingPaymentTransactionEventVersion;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final readonly class HoldingPerformanceImmutableEventSource
{
    public function coverageStartedAt(
        DateTimeInterface $contextStartedAt,
        DateTimeZone $timezone,
    ): DateTimeImmutable
    {
        $checkpoint = HoldingPaymentEventCoverageCheckpoint::query()
            ->orderByDesc('id')
            ->first();
        if (! $checkpoint instanceof HoldingPaymentEventCoverageCheckpoint
            || $checkpoint->started_at === null
            || ! $this->validPaymentCheckpoint($checkpoint)) {
            throw new InvalidArgumentException('holding_payment_event_coverage_unavailable');
        }

        $context = DateTimeImmutable::createFromInterface($contextStartedAt);
        $payment = DateTimeImmutable::createFromInterface($checkpoint->started_at);

        return $this->firstCompleteBusinessDay(
            $payment > $context ? $payment : $context,
            $timezone,
        );
    }

    public function firstCompleteBusinessDay(
        DateTimeInterface $startedAt,
        DateTimeZone $timezone,
    ): DateTimeImmutable {
        $local = DateTimeImmutable::createFromInterface($startedAt)->setTimezone($timezone);
        $startOfDay = $local->setTime(0, 0);

        return $local > $startOfDay ? $startOfDay->modify('+1 day') : $startOfDay;
    }

    public function assertPeriodCovered(
        array $filters,
        DateTimeInterface $coverageStartedAt,
        DateTimeZone $timezone,
    ): void {
        $coverageDate = DateTimeImmutable::createFromInterface($coverageStartedAt)
            ->setTimezone($timezone)
            ->format('Y-m-d');
        foreach (['period_from', 'period_to'] as $field) {
            if (! isset($filters[$field])) {
                continue;
            }
            if (! is_string($filters[$field])
                || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $filters[$field]) !== 1) {
                throw new InvalidArgumentException('holding_performance_period_invalid');
            }
            if ($filters[$field] < $coverageDate) {
                throw new InvalidArgumentException('holding_performance_period_outside_coverage');
            }
        }
    }

    public function acceptedWorkVersions(
        array $organizationIds,
        array $projectIds,
        DateTimeInterface $coverageStartedAt,
        DateTimeInterface $asOf,
        DateTimeInterface $recordedCutoff,
    ): Collection {
        $organizations = $this->identities($organizationIds);
        $projects = $this->identities($projectIds);

        return HoldingAcceptedWorkEventVersion::query()
            ->whereIn('organization_id', $organizations)
            ->whereIn('project_id', $projects)
            ->whereBetween('occurred_at', [$coverageStartedAt, $asOf])
            ->where('recorded_at', '<=', $recordedCutoff)
            ->whereNotExists(function (QueryBuilder $newer) use ($asOf, $recordedCutoff): void {
                $newer
                    ->selectRaw('1')
                    ->from('holding_accepted_work_event_versions as newer_event')
                    ->whereColumn(
                        'newer_event.performance_act_id',
                        'holding_accepted_work_event_versions.performance_act_id',
                    )
                    ->where('newer_event.occurred_at', '<=', $asOf)
                    ->where('newer_event.recorded_at', '<=', $recordedCutoff)
                    ->where(fn (QueryBuilder $tuple): QueryBuilder => $this->newerCaptureTuple(
                        $tuple,
                        'newer_event',
                        'holding_accepted_work_event_versions',
                    ));
            })
            ->where(function ($relevant) use ($asOf, $recordedCutoff): void {
                $relevant
                    ->where('active', true)
                    ->orWhereExists(function (QueryBuilder $prior) use ($asOf, $recordedCutoff): void {
                        $prior
                            ->selectRaw('1')
                            ->from('holding_accepted_work_event_versions as prior_event')
                            ->whereColumn(
                                'prior_event.performance_act_id',
                                'holding_accepted_work_event_versions.performance_act_id',
                            )
                            ->where('prior_event.active', true)
                            ->where('prior_event.occurred_at', '<=', $asOf)
                            ->where('prior_event.recorded_at', '<=', $recordedCutoff)
                            ->where(fn (QueryBuilder $tuple): QueryBuilder => $this->olderCaptureTuple(
                                $tuple,
                                'prior_event',
                                'holding_accepted_work_event_versions',
                            ));
                    });
            })
            ->orderBy('id')
            ->get();
    }

    public function paymentVersions(
        array $organizationIds,
        array $projectIds,
        DateTimeInterface $coverageStartedAt,
        DateTimeInterface $asOf,
        DateTimeInterface $recordedCutoff,
    ): Collection {
        $organizations = $this->identities($organizationIds);
        $projects = $this->identities($projectIds);

        return HoldingPaymentTransactionEventVersion::query()
            ->whereIn('organization_id', $organizations)
            ->where(function ($scope) use ($projects): void {
                $scope
                    ->whereIn('project_id', $projects)
                    ->orWhere(fn ($gap) => $gap
                        ->whereNull('project_id')
                        ->where('history_complete', false));
            })
            ->where(function ($window) use ($coverageStartedAt, $asOf): void {
                $window
                    ->whereBetween('recognized_at', [$coverageStartedAt, $asOf])
                    ->orWhere(fn ($gap) => $gap
                        ->where('history_complete', false)
                        ->whereBetween('occurred_at', [$coverageStartedAt, $asOf]));
            })
            ->where('occurred_at', '<=', $asOf)
            ->where('recorded_at', '<=', $recordedCutoff)
            ->whereNotExists(function (QueryBuilder $newer) use ($asOf, $recordedCutoff): void {
                $newer
                    ->selectRaw('1')
                    ->from('holding_payment_transaction_event_versions as newer_event')
                    ->whereColumn(
                        'newer_event.transaction_id',
                        'holding_payment_transaction_event_versions.transaction_id',
                    )
                    ->where('newer_event.occurred_at', '<=', $asOf)
                    ->where('newer_event.recorded_at', '<=', $recordedCutoff)
                    ->where(fn (QueryBuilder $tuple): QueryBuilder => $this->newerCaptureTuple(
                        $tuple,
                        'newer_event',
                        'holding_payment_transaction_event_versions',
                    ));
            })
            ->where(function ($relevant) use ($asOf, $recordedCutoff): void {
                $relevant
                    ->where('active', true)
                    ->orWhereExists(function (QueryBuilder $prior) use ($asOf, $recordedCutoff): void {
                        $prior
                            ->selectRaw('1')
                            ->from('holding_payment_transaction_event_versions as prior_event')
                            ->whereColumn(
                                'prior_event.transaction_id',
                                'holding_payment_transaction_event_versions.transaction_id',
                            )
                            ->where('prior_event.active', true)
                            ->where('prior_event.occurred_at', '<=', $asOf)
                            ->where('prior_event.recorded_at', '<=', $recordedCutoff)
                            ->where(fn (QueryBuilder $tuple): QueryBuilder => $this->olderCaptureTuple(
                                $tuple,
                                'prior_event',
                                'holding_payment_transaction_event_versions',
                            ));
                    });
            })
            ->orderBy('id')
            ->get();
    }

    private function validPaymentCheckpoint(HoldingPaymentEventCoverageCheckpoint $checkpoint): bool
    {
        $sourceCount = (int) $checkpoint->source_count;
        $capturedCount = (int) $checkpoint->captured_count;
        $gapCount = (int) $checkpoint->gap_count;
        $contentHash = (string) $checkpoint->content_hash;
        if (min($sourceCount, $capturedCount, $gapCount) < 0
            || $sourceCount !== $capturedCount + $gapCount
            || preg_match('/^[a-f0-9]{64}$/D', $contentHash) !== 1) {
            return false;
        }

        $evidence = HoldingPaymentTransactionEventVersion::query()
            ->where('recorded_at', $checkpoint->started_at)
            ->selectRaw('COUNT(*) AS source_count')
            ->selectRaw('COUNT(*) FILTER (WHERE history_complete) AS captured_count')
            ->selectRaw('COUNT(*) FILTER (WHERE NOT history_complete) AS gap_count')
            ->selectRaw('COALESCE(MAX(transaction_id), 0) AS source_max_transaction_id')
            ->selectRaw(
                "encode(sha256(convert_to(COALESCE(string_agg(source_hash, '|' "
                .'ORDER BY transaction_id, id), \'\'), \'UTF8\')), \'hex\') AS content_hash',
            )
            ->first();

        return $evidence instanceof HoldingPaymentTransactionEventVersion
            && (int) $evidence->source_count === $sourceCount
            && (int) $evidence->captured_count === $capturedCount
            && (int) $evidence->gap_count === $gapCount
            && (int) $evidence->source_max_transaction_id === (int) $checkpoint->source_max_transaction_id
            && hash_equals($contentHash, (string) $evidence->content_hash);
    }

    private function identities(array $ids): array
    {
        $normalized = array_values(array_unique(array_map('intval', $ids)));
        sort($normalized, SORT_NUMERIC);
        if ($normalized === [] || array_filter($normalized, static fn (int $id): bool => $id < 1) !== []) {
            throw new InvalidArgumentException('holding_performance_scope_invalid');
        }

        return $normalized;
    }

    private function newerCaptureTuple(QueryBuilder $query, string $candidate, string $base): QueryBuilder
    {
        return $query
            ->whereColumn($candidate.'.recorded_at', '>', $base.'.recorded_at')
            ->orWhere(fn (QueryBuilder $sameRecorded): QueryBuilder => $sameRecorded
                ->whereColumn($candidate.'.recorded_at', $base.'.recorded_at')
                ->whereColumn($candidate.'.id', '>', $base.'.id'));
    }

    private function olderCaptureTuple(QueryBuilder $query, string $candidate, string $base): QueryBuilder
    {
        return $query
            ->whereColumn($candidate.'.recorded_at', '<', $base.'.recorded_at')
            ->orWhere(fn (QueryBuilder $sameRecorded): QueryBuilder => $sameRecorded
                ->whereColumn($candidate.'.recorded_at', $base.'.recorded_at')
                ->whereColumn($candidate.'.id', '<', $base.'.id'));
    }
}
