<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\Models\ContractPerformanceAct;
use App\Services\CompletedWork\Reporting\AcceptedProduction\DTO\AcceptedProductionHistoryBoundary;
use App\Services\CompletedWork\Reporting\AcceptedProduction\DTO\AcceptedProductionUniverseEntry;
use App\Services\CompletedWork\Reporting\AcceptedProduction\DTO\AcceptedProductionUniverseStream;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceBackfillLedger;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceOwnerMember;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceOwnerVersion;
use App\Support\Reporting\DeterministicObjectSpool;
use App\Support\Reporting\ReportScopedResourceFilter;
use App\Support\Reporting\StableReportingSourceView;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final readonly class AcceptedProductionEventUniverse
{
    private StableReportingSourceView $stableView;
    private AcceptedProductionHistoryBoundaryResolver $boundaries;
    private ProductionAcceptanceRecognitionGrain $grain;
    private AcceptedProductionFilterValidator $filterValidator;

    public function __construct(
        ?AcceptedProductionHistoryBoundaryResolver $boundaries = null,
        ?StableReportingSourceView $stableView = null,
        ?ProductionAcceptanceRecognitionGrain $grain = null,
        ?AcceptedProductionFilterValidator $filterValidator = null,
    ) {
        $this->boundaries = $boundaries ?? new AcceptedProductionHistoryBoundaryResolver;
        $this->stableView = $stableView ?? new StableReportingSourceView;
        $this->grain = $grain ?? new ProductionAcceptanceRecognitionGrain;
        $this->filterValidator = $filterValidator ?? new AcceptedProductionFilterValidator;
    }

    public function stream(ReportScope $scope, ReportQuery $query): AcceptedProductionUniverseStream
    {
        $projectId = $this->filterValidator->validate($query);

        return $this->stableView->capture(
            fn (): AcceptedProductionUniverseStream => $this->captureStream($scope, $query, $projectId),
            3,
        );
    }

    private function captureStream(
        ReportScope $scope,
        ReportQuery $query,
        int $projectId,
    ): AcceptedProductionUniverseStream {
        $boundary = $this->boundaries->resolve($scope, $query);
        [$projectIds, $workIds, $actIds, $actLineIds] = $this->filters($scope, $query, $projectId);
        $entries = new DeterministicObjectSpool;
        $gaps = new DeterministicObjectSpool;
        $eventWatermark = $boundary->eventWatermarkId;
        $ownerWatermark = $boundary->ownerVersionWatermarkId;
        $ownerMemberWatermark = $boundary->ownerMemberWatermarkId;

        $dailyReducers = [];
        $currentPerformanceActId = null;
        $ownerQuery = $this->ownerQuery($scope, $query, $boundary, $projectIds, $actIds)
            ->orderBy('performance_act_id')
            ->orderBy('effective_at')
            ->orderBy('version')
            ->orderBy('id');
        foreach ($ownerQuery->cursor() as $owner) {
            if ($currentPerformanceActId !== null
                && $currentPerformanceActId !== (int) $owner->performance_act_id
            ) {
                $this->flushDailyReducers($entries, $dailyReducers);
            }
            $currentPerformanceActId = (int) $owner->performance_act_id;
            $ownerWatermark = max($ownerWatermark, (int) $owner->id);
            ProductionAcceptanceOwnerMember::query()
                ->where('owner_version_id', (int) $owner->id)
                ->where('id', '>', $boundary->ownerMemberWatermarkId)
                ->where('organization_id', $scope->organizationId)
                ->where('project_id', (int) $owner->project_id)
                ->where('performance_act_id', (int) $owner->performance_act_id)
                ->chunkById(500, function ($memberPage) use (
                    $boundary,
                    $owner,
                    $scope,
                    $query,
                    $workIds,
                    $actIds,
                    $actLineIds,
                    $gaps,
                    &$dailyReducers,
                    &$eventWatermark,
                    &$ownerMemberWatermark,
                ): void {
                    $candidates = [];
                    foreach ($memberPage as $memberRow) {
                        $ownerMemberWatermark = max($ownerMemberWatermark, (int) $memberRow->id);
                        $member = [
                            'contractor_id' => $memberRow->contractor_id,
                            'source_line_id' => (int) $memberRow->source_line_id,
                            'source_line_type' => (string) $memberRow->source_line_type,
                            'unit_code' => (string) $memberRow->unit_code,
                            'work_id' => (int) $memberRow->work_id,
                            'zone' => $memberRow->zone,
                        ];
                        if (! $this->matchesMember($member, $workIds, $actLineIds, $query)) {
                            continue;
                        }
                        $candidate = [
                            'event_type' => (string) $owner->event_type,
                            'effective_at' => $owner->effective_at->format(DATE_ATOM),
                            'owner_source_hash' => (string) $owner->source_hash,
                            'owner_version_id' => (int) $owner->id,
                            'performance_act_id' => (int) $owner->performance_act_id,
                            'project_id' => (int) $owner->project_id,
                            'source_line_id' => (int) $member['source_line_id'],
                            'source_line_type' => (string) $member['source_line_type'],
                            'work_id' => (int) $member['work_id'],
                        ];
                        $key = $this->key($candidate);
                        if ($this->candidateMatchesState($candidate, $scope, $query)) {
                            $candidates[$key] = $candidate;
                        }
                    }
                    if ($candidates === []) {
                        return;
                    }
                    $eventWatermark = max(
                        $eventWatermark,
                        $this->appendCandidateEntries(
                            $scope,
                            $query,
                            (int) $owner->performance_act_id,
                            $candidates,
                            $workIds,
                            $actIds,
                            $actLineIds,
                            $gaps,
                            $boundary,
                            $dailyReducers,
                        ),
                    );
                });
            $orphanQuery = ProductionAcceptanceEvent::query()
                ->where('organization_id', $scope->organizationId)
                ->where('project_id', (int) $owner->project_id)
                ->where('performance_act_id', (int) $owner->performance_act_id)
                ->where('event_type', (string) $owner->event_type)
                ->where('recognized_at', $owner->effective_at)
                ->where('id', '>', $boundary->eventWatermarkId)
                ->where('recognized_at', '>=', $boundary->completedAt)
                ->where('recognized_at', '<=', $query->asOf)
                ->whereNotExists(static function ($builder) use ($boundary, $owner, $scope): void {
                    $builder
                        ->selectRaw('1')
                        ->from('production_acceptance_owner_members as owner_member')
                        ->where('owner_member.owner_version_id', (int) $owner->id)
                        ->where('owner_member.id', '>', $boundary->ownerMemberWatermarkId)
                        ->where('owner_member.organization_id', $scope->organizationId)
                        ->whereColumn(
                            'owner_member.source_line_type',
                            'production_acceptance_events.source_line_type',
                        )
                        ->whereColumn(
                            'owner_member.source_line_id',
                            'production_acceptance_events.source_line_id',
                        );
                });
            $this->applyEventScalarFilters($orphanQuery, $query, $workIds, $actIds, $actLineIds);
            foreach ($orphanQuery->lazyById(500) as $event) {
                $eventWatermark = max($eventWatermark, (int) $event->id);
                if ($this->eventMatchesState($event, $scope, $query)) {
                    $this->appendGap($gaps, [
                        'event_id' => (int) $event->id,
                        'kind' => 'owner_version_missing',
                        'performance_act_id' => (int) $event->performance_act_id,
                        'source_line_id' => (int) $event->source_line_id,
                        'source_line_type' => (string) $event->source_line_type,
                    ]);
                }
            }
        }
        $this->flushDailyReducers($entries, $dailyReducers);
        $ownerlessEvents = ProductionAcceptanceEvent::query()
            ->where('organization_id', $scope->organizationId)
            ->whereIn('project_id', $projectIds)
            ->where('id', '>', $boundary->eventWatermarkId)
            ->where('recognized_at', '>=', $boundary->completedAt)
            ->where('recognized_at', '<=', $query->asOf)
            ->whereNotExists(static function ($builder) use ($boundary, $scope, $query): void {
                $builder
                    ->selectRaw('1')
                    ->from('production_acceptance_owner_versions as owner_coverage')
                    ->whereColumn(
                        'owner_coverage.performance_act_id',
                        'production_acceptance_events.performance_act_id',
                    )
                    ->where('owner_coverage.organization_id', $scope->organizationId)
                    ->where('owner_coverage.id', '>', $boundary->ownerVersionWatermarkId)
                    ->where('owner_coverage.effective_at', '>=', $boundary->completedAt)
                    ->where('owner_coverage.effective_at', '<=', $query->asOf)
                    ->whereColumn('owner_coverage.event_type', 'production_acceptance_events.event_type')
                    ->whereColumn('owner_coverage.project_id', 'production_acceptance_events.project_id')
                    ->whereColumn('owner_coverage.effective_at', 'production_acceptance_events.recognized_at');
            });
        $this->applyEventScalarFilters(
            $ownerlessEvents,
            $query,
            $workIds,
            $actIds,
            $actLineIds,
        );
        foreach ($ownerlessEvents->lazyById(500) as $event) {
            if (! $this->eventMatchesState($event, $scope, $query)) {
                continue;
            }
            $eventWatermark = max($eventWatermark, (int) $event->id);
            $this->appendGap($gaps, [
                'event_id' => (int) $event->id,
                'kind' => 'owner_version_missing',
                'performance_act_id' => (int) $event->performance_act_id,
                'source_line_id' => (int) $event->source_line_id,
                'source_line_type' => (string) $event->source_line_type,
                'work_id' => (int) $event->work_id,
            ]);
        }

        foreach ($this->coverageGapRows($scope, $query, $boundary, $projectIds, $actIds) as $gap) {
            $this->appendGap($gaps, $gap);
        }

        return new AcceptedProductionUniverseStream(
            $entries,
            $gaps,
            $eventWatermark,
            $ownerWatermark,
            $ownerMemberWatermark,
            $boundary,
        );
    }

    private function appendCandidateEntries(
        ReportScope $scope,
        ReportQuery $query,
        int $performanceActId,
        array $candidates,
        ?array $workIds,
        ?array $actIds,
        ?array $actLineIds,
        DeterministicObjectSpool $gaps,
        AcceptedProductionHistoryBoundary $boundary,
        array &$dailyReducers,
    ): int {
        uasort(
            $candidates,
            static fn (array $left, array $right): int => [
                $left['source_line_type'],
                $left['source_line_id'],
            ] <=> [
                $right['source_line_type'],
                $right['source_line_id'],
            ],
        );
        $owner = reset($candidates);
        if (! is_array($owner)
            || ! is_string($owner['event_type'] ?? null)
            || ! is_string($owner['effective_at'] ?? null)
            || (int) ($owner['project_id'] ?? 0) < 1
        ) {
            throw new InvalidArgumentException('accepted_production_candidate_invalid');
        }
        $eventQuery = ProductionAcceptanceEvent::query()
            ->where('organization_id', $scope->organizationId)
            ->where('project_id', (int) $owner['project_id'])
            ->where('performance_act_id', $performanceActId)
            ->where('event_type', $owner['event_type'])
            ->where('recognized_at', $owner['effective_at'])
            ->where('id', '>', $boundary->eventWatermarkId)
            ->where('recognized_at', '>=', $boundary->completedAt)
            ->where('recognized_at', '<=', $query->asOf)
            ->whereIn(
                'source_line_id',
                array_values(array_unique(array_map(
                    static fn (array $candidate): int => (int) $candidate['source_line_id'],
                    $candidates,
                ))),
            );
        $this->applyEventScalarFilters($eventQuery, $query, $workIds, $actIds, $actLineIds);
        $eventQuery
            ->orderBy('performance_act_id')
            ->orderBy('source_line_type')
            ->orderBy('source_line_id')
            ->orderBy('transition_version')
            ->orderBy('id');

        $watermark = 0;
        foreach ($eventQuery->cursor() as $event) {
            $watermark = max($watermark, (int) $event->id);
            if (! $this->eventMatchesState($event, $scope, $query)) {
                continue;
            }
            $key = $this->key([
                'performance_act_id' => (int) $event->performance_act_id,
                'source_line_id' => (int) $event->source_line_id,
                'source_line_type' => (string) $event->source_line_type,
                'work_id' => (int) $event->work_id,
            ]);
            if (! isset($candidates[$key])) {
                continue;
            }
            $candidate = $candidates[$key];
            $dailyKey = $this->grain->key($event, $scope->timezone);
            $dailyReducers[$dailyKey] ??= new AcceptedProductionEventReducer($candidate);
            $dailyReducers[$dailyKey]->append($event, $candidate);
            unset($candidates[$key]);
        }
        foreach ($candidates as $candidate) {
            $this->appendGap($gaps, [
                'kind' => 'acceptance_event_missing',
                'owner_version_id' => (int) $candidate['owner_version_id'],
                'performance_act_id' => (int) $candidate['performance_act_id'],
                'source_line_id' => (int) $candidate['source_line_id'],
                'source_line_type' => (string) $candidate['source_line_type'],
            ]);
        }

        return $watermark;
    }

    private function flushDailyReducers(
        DeterministicObjectSpool $entries,
        array &$dailyReducers,
    ): void {
        ksort($dailyReducers, SORT_STRING);
        foreach ($dailyReducers as $reducer) {
            if (! $reducer instanceof AcceptedProductionEventReducer) {
                throw new InvalidArgumentException('accepted_production_event_group_invalid');
            }
            $this->appendEntry($entries, $reducer->finish());
        }
        $dailyReducers = [];
    }

    private function appendEntry(
        DeterministicObjectSpool $entries,
        AcceptedProductionUniverseEntry $entry,
    ): void {
        $entries->append(
            $entry,
            $entry->canonicalIdentity(),
        );
    }

    private function filters(ReportScope $scope, ReportQuery $query, int $projectId): array
    {
        $resources = new ReportScopedResourceFilter;
        $workIds = $this->intersect(
            $resources->ids($scope, ['work', 'completed_work'], [$projectId]),
            $this->positiveIntegerFilter($query, 'work_ids'),
        );
        $actIds = $this->intersect(
            $resources->ids($scope, ['act', 'performance_act', 'contract_performance_act'], [$projectId]),
            $this->positiveIntegerFilter($query, 'act_ids'),
        );

        return [
            [$projectId],
            $workIds,
            $actIds,
            $resources->ids($scope, ['act_line', 'performance_act_line'], [$projectId]),
        ];
    }

    private function ownerQuery(
        ReportScope $scope,
        ReportQuery $query,
        AcceptedProductionHistoryBoundary $boundary,
        array $projectIds,
        ?array $actIds,
    ): Builder {
        return ProductionAcceptanceOwnerVersion::query()
            ->where('organization_id', $scope->organizationId)
            ->whereIn('project_id', $projectIds)
            ->where('id', '>', $boundary->ownerVersionWatermarkId)
            ->where('effective_at', '>=', $boundary->completedAt)
            ->where('effective_at', '<=', $query->asOf)
            ->whereNotExists(static function ($builder) use ($boundary): void {
                $builder
                    ->selectRaw('1')
                    ->from('production_acceptance_owner_versions as owner_same_transition')
                    ->whereColumn(
                        'owner_same_transition.organization_id',
                        'production_acceptance_owner_versions.organization_id',
                    )
                    ->whereColumn(
                        'owner_same_transition.performance_act_id',
                        'production_acceptance_owner_versions.performance_act_id',
                    )
                    ->whereColumn(
                        'owner_same_transition.event_type',
                        'production_acceptance_owner_versions.event_type',
                    )
                    ->whereColumn(
                        'owner_same_transition.effective_at',
                        'production_acceptance_owner_versions.effective_at',
                    )
                    ->whereColumn(
                        'owner_same_transition.version',
                        '>',
                        'production_acceptance_owner_versions.version',
                    )
                    ->where('owner_same_transition.id', '>', $boundary->ownerVersionWatermarkId);
            })
            ->when(
                $actIds !== null,
                static fn (Builder $builder) => $builder->whereIn('performance_act_id', $actIds),
            );
    }

    private function coverageGapRows(
        ReportScope $scope,
        ReportQuery $query,
        AcceptedProductionHistoryBoundary $boundary,
        array $projectIds,
        ?array $actIds,
    ): \Generator {
        [$from, $to] = $this->period($query->filters->values);
        $coverageStart = $boundary->coverageStartDay($scope->timezone);
        $asOfDay = $query->asOf->setTimezone($scope->timezone)->format('Y-m-d');
        $timezone = $scope->timezone->getName();
        $effectiveRecognitionDate = <<<'SQL'
CASE
    WHEN signed_at IS NOT NULL AND signed_at <= ?
        THEN (signed_at AT TIME ZONE ?)::date
    ELSE approval_date
END
SQL;
        $acts = ContractPerformanceAct::query()
            ->whereIn('project_id', $projectIds)
            ->whereHas('contract', static fn (Builder $builder) => $builder
                ->where('organization_id', $scope->organizationId))
            ->whereRaw(
                "({$effectiveRecognitionDate}) BETWEEN ? AND ?",
                [$query->asOf, $timezone, $coverageStart, $asOfDay],
            )
            ->whereNotExists(static function ($builder) use ($boundary, $scope, $query): void {
                $builder
                    ->selectRaw('1')
                    ->from('production_acceptance_owner_versions as owner_coverage')
                    ->whereColumn('owner_coverage.performance_act_id', 'contract_performance_acts.id')
                    ->where('owner_coverage.organization_id', $scope->organizationId)
                    ->where('owner_coverage.id', '>', $boundary->ownerVersionWatermarkId)
                    ->where('owner_coverage.effective_at', '>=', $boundary->completedAt)
                    ->where('owner_coverage.effective_at', '<=', $query->asOf);
            })
            ->when($from !== null, static function (Builder $builder) use (
                $effectiveRecognitionDate,
                $from,
                $query,
                $timezone,
            ): void {
                $builder->whereRaw(
                    "({$effectiveRecognitionDate}) >= ?",
                    [$query->asOf, $timezone, $from],
                );
            })
            ->when($to !== null, static function (Builder $builder) use (
                $effectiveRecognitionDate,
                $query,
                $timezone,
                $to,
            ): void {
                $builder->whereRaw(
                    "({$effectiveRecognitionDate}) <= ?",
                    [$query->asOf, $timezone, $to],
                );
            })
            ->when($actIds !== null, static fn (Builder $builder) => $builder->whereIn('id', $actIds));
        foreach ($acts->lazyById(500) as $act) {
            yield [
                'kind' => 'legacy_owner_unprovable',
                'performance_act_id' => (int) $act->id,
                'project_id' => (int) $act->project_id,
                'reason' => 'historical_membership_unprovable',
            ];
        }

        $queryBuilder = ProductionAcceptanceBackfillLedger::query()
            ->where('organization_id', $scope->organizationId)
            ->whereIn('project_id', $projectIds)
            ->where('id', '>', $boundary->backfillLedgerWatermarkId)
            ->where('recorded_at', '>=', $boundary->completedAt)
            ->where('recognized_at', '<=', $query->asOf)
            ->where('status', 'unprovable')
            ->when($from !== null, static fn (Builder $builder) => $builder
                ->whereRaw('(recognized_at AT TIME ZONE ?)::date >= ?', [$timezone, $from]))
            ->when($to !== null, static fn (Builder $builder) => $builder
                ->whereRaw('(recognized_at AT TIME ZONE ?)::date <= ?', [$timezone, $to]))
            ->when($actIds !== null, static fn (Builder $builder) => $builder
                ->whereIn('performance_act_id', $actIds));
        foreach ($queryBuilder->lazyById(500) as $ledger) {
            yield [
                'kind' => 'legacy_owner_unprovable',
                'ledger_id' => (int) $ledger->id,
                'performance_act_id' => (int) $ledger->performance_act_id,
                'reason' => (string) $ledger->reason,
            ];
        }
    }

    private function appendGap(DeterministicObjectSpool $spool, array $gap): void
    {
        $spool->append((object) $gap, $gap);
    }

    private function candidateMatchesState(
        array $candidate,
        ReportScope $scope,
        ReportQuery $query,
    ): bool {
        [$from, $to] = $this->period($query->filters->values);
        $date = (new \DateTimeImmutable((string) $candidate['effective_at']))
            ->setTimezone($scope->timezone)
            ->format('Y-m-d');

        return $this->matches($query->filters->values['statuses'] ?? [], $candidate['event_type'])
            && ($from === null || $date >= $from)
            && ($to === null || $date <= $to);
    }

    private function eventMatchesState(
        ProductionAcceptanceEvent $event,
        ReportScope $scope,
        ReportQuery $query,
    ): bool
    {
        [$from, $to] = $this->period($query->filters->values);
        $date = $event->recognized_at->setTimezone($scope->timezone)->format('Y-m-d');

        return $this->matches($query->filters->values['statuses'] ?? [], (string) $event->event_type)
            && ($from === null || $date >= $from)
            && ($to === null || $date <= $to);
    }

    private function applyEventScalarFilters(
        Builder $builder,
        ReportQuery $query,
        ?array $workIds,
        ?array $actIds,
        ?array $actLineIds,
    ): void {
        $values = $query->filters->values;
        $builder
            ->when($workIds !== null, static fn (Builder $queryBuilder) => $queryBuilder->whereIn('work_id', $workIds))
            ->when(
                $actIds !== null,
                static fn (Builder $queryBuilder) => $queryBuilder->whereIn('performance_act_id', $actIds),
            )
            ->when(
                $actLineIds !== null,
                static fn (Builder $queryBuilder) => $queryBuilder
                    ->where('source_line_type', 'performance_act_line')
                    ->whereIn('source_line_id', $actLineIds),
            )
            ->when(
                ($values['contractor_ids'] ?? []) !== [],
                static fn (Builder $queryBuilder) => $queryBuilder->whereIn(
                    'contractor_id',
                    array_map('intval', $values['contractor_ids']),
                ),
            )
            ->when(
                ($values['unit_codes'] ?? []) !== [],
                static fn (Builder $queryBuilder) => $queryBuilder->whereIn(
                    'unit_code',
                    array_map('strval', $values['unit_codes']),
                ),
            )
            ->when(
                ($values['zones'] ?? []) !== [],
                static fn (Builder $queryBuilder) => $queryBuilder->whereIn(
                    'zone',
                    array_map('strval', $values['zones']),
                ),
            );
    }

    private function matchesMember(
        mixed $member,
        ?array $workIds,
        ?array $actLineIds,
        ReportQuery $query,
    ): bool {
        if (! is_array($member)
            || ! is_numeric($member['source_line_id'] ?? null)
            || ! is_string($member['source_line_type'] ?? null)
            || ! is_numeric($member['work_id'] ?? null)
        ) {
            throw new InvalidArgumentException('production_acceptance_owner_member_invalid');
        }
        if ($workIds !== null && ! in_array((int) $member['work_id'], $workIds, true)) {
            return false;
        }
        if ($actLineIds !== null
            && (($member['source_line_type'] ?? null) !== 'performance_act_line'
                || ! in_array((int) $member['source_line_id'], $actLineIds, true))
        ) {
            return false;
        }
        $values = $query->filters->values;

        return $this->matches($values['contractor_ids'] ?? [], $member['contractor_id'] ?? null)
            && $this->matches($values['unit_codes'] ?? [], $member['unit_code'] ?? null)
            && $this->matches($values['zones'] ?? [], $member['zone'] ?? null);
    }

    private function period(array $values): array
    {
        $from = $values['period_from'] ?? null;
        $to = $values['period_to'] ?? null;
        foreach ([$from, $to] as $date) {
            if ($date !== null
                && (! is_string($date)
                    || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1
                    || \DateTimeImmutable::createFromFormat('!Y-m-d', $date)?->format('Y-m-d') !== $date)
            ) {
                throw new InvalidArgumentException('accepted_production_period_filter_invalid');
            }
        }
        if ($from !== null && $to !== null && $from > $to) {
            throw new InvalidArgumentException('accepted_production_period_filter_invalid');
        }

        return [$from, $to];
    }

    private function positiveIntegerFilter(
        ReportQuery $query,
        string $key,
    ): ?array {
        $value = $query->filters->values[$key] ?? null;
        if ($value === null || $value === []) {
            return null;
        }
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('accepted_production_filter_invalid');
        }
        $result = array_values(array_unique(array_map('intval', $value)));
        if (array_filter($result, static fn (int $id): bool => $id < 1) !== []) {
            throw new InvalidArgumentException('accepted_production_filter_invalid');
        }

        return $result;
    }

    private function intersect(?array $left, ?array $right): ?array
    {
        if ($left === null) {
            return $right;
        }
        if ($right === null) {
            return $left;
        }

        return array_values(array_intersect($left, $right));
    }

    private function matches(mixed $filter, mixed $value): bool
    {
        if ($filter === []) {
            return true;
        }
        if (! is_array($filter) || ! array_is_list($filter) || $value === null) {
            return false;
        }

        return in_array((string) $value, array_map('strval', $filter), true);
    }

    private function key(array $source): string
    {
        return implode(':', [
            (int) $source['performance_act_id'],
            (string) $source['source_line_type'],
            (int) $source['source_line_id'],
            (int) $source['work_id'],
        ]);
    }
}
