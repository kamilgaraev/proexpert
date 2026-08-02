<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\Models\ContractPerformanceAct;
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

    public function __construct(?StableReportingSourceView $stableView = null)
    {
        $this->stableView = $stableView ?? new StableReportingSourceView;
    }

    public function stream(ReportScope $scope, ReportQuery $query): AcceptedProductionUniverseStream
    {
        return $this->stableView->capture(
            fn (): AcceptedProductionUniverseStream => $this->captureStream($scope, $query),
            3,
        );
    }

    private function captureStream(
        ReportScope $scope,
        ReportQuery $query,
    ): AcceptedProductionUniverseStream {
        [$projectIds, $workIds, $actIds, $actLineIds] = $this->filters($scope, $query);
        $entries = new DeterministicObjectSpool;
        $gaps = new DeterministicObjectSpool;
        $eventWatermark = 0;
        $ownerWatermark = 0;

        $ownerQuery = $this->ownerQuery($scope, $query, $projectIds, $actIds);
        foreach ($ownerQuery->lazyById(100) as $owner) {
            $ownerWatermark = max($ownerWatermark, (int) $owner->id);
            ProductionAcceptanceOwnerMember::query()
                ->where('owner_version_id', (int) $owner->id)
                ->chunkById(500, function ($memberPage) use (
                    $owner,
                    $scope,
                    $query,
                    $workIds,
                    $actIds,
                    $actLineIds,
                    $entries,
                    $gaps,
                    &$eventWatermark,
                ): void {
                    $candidates = [];
                    foreach ($memberPage as $memberRow) {
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
                        if ($this->candidateMatchesState($candidate, $query)) {
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
                            $entries,
                            $gaps,
                        ),
                    );
                });
            $orphanQuery = ProductionAcceptanceEvent::query()
                ->where('organization_id', $scope->organizationId)
                ->where('performance_act_id', (int) $owner->performance_act_id)
                ->where('recognized_at', '<=', $query->asOf)
                ->whereNotExists(static function ($builder) use ($owner): void {
                    $builder
                        ->selectRaw('1')
                        ->from('production_acceptance_owner_members as owner_member')
                        ->where('owner_member.owner_version_id', (int) $owner->id)
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
                $key = $this->key([
                    'performance_act_id' => (int) $event->performance_act_id,
                    'source_line_id' => (int) $event->source_line_id,
                    'source_line_type' => (string) $event->source_line_type,
                ]);
                if ($this->eventMatchesState($event, $query)) {
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
        $ownerlessEvents = ProductionAcceptanceEvent::query()
            ->where('organization_id', $scope->organizationId)
            ->whereIn('project_id', $projectIds)
            ->where('recognized_at', '<=', $query->asOf)
            ->whereNotExists(static function ($builder) use ($scope, $query): void {
                $builder
                    ->selectRaw('1')
                    ->from('production_acceptance_owner_versions as owner_coverage')
                    ->whereColumn(
                        'owner_coverage.performance_act_id',
                        'production_acceptance_events.performance_act_id',
                    )
                    ->where('owner_coverage.organization_id', $scope->organizationId)
                    ->where('owner_coverage.effective_at', '<=', $query->asOf);
            });
        $this->applyEventScalarFilters(
            $ownerlessEvents,
            $query,
            $workIds,
            $actIds,
            $actLineIds,
        );
        foreach ($ownerlessEvents->lazyById(500) as $event) {
            if (! $this->eventMatchesState($event, $query)) {
                continue;
            }
            $eventWatermark = max($eventWatermark, (int) $event->id);
            $this->appendGap($gaps, [
                'event_id' => (int) $event->id,
                'kind' => 'owner_version_missing',
                'performance_act_id' => (int) $event->performance_act_id,
                'source_line_id' => (int) $event->source_line_id,
                'source_line_type' => (string) $event->source_line_type,
            ]);
        }

        foreach ($this->legacyGapRows($scope, $query, $projectIds, $actIds) as $gap) {
            $this->appendGap($gaps, $gap);
        }

        return new AcceptedProductionUniverseStream(
            $entries,
            $gaps,
            $eventWatermark,
            $ownerWatermark,
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
        DeterministicObjectSpool $entries,
        DeterministicObjectSpool $gaps,
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
        $eventQuery = ProductionAcceptanceEvent::query()
            ->where('organization_id', $scope->organizationId)
            ->where('performance_act_id', $performanceActId)
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

        $currentKey = null;
        $currentReducer = null;
        $watermark = 0;
        foreach ($eventQuery->cursor() as $event) {
            $watermark = max($watermark, (int) $event->id);
            if (! $this->eventMatchesState($event, $query)) {
                continue;
            }
            $key = $this->key([
                'performance_act_id' => (int) $event->performance_act_id,
                'source_line_id' => (int) $event->source_line_id,
                'source_line_type' => (string) $event->source_line_type,
            ]);
            if (! isset($candidates[$key])) {
                continue;
            }
            if ($currentKey !== null && $currentKey !== $key) {
                $this->appendEntry(
                    $entries,
                    $currentReducer?->finish()
                        ?? throw new InvalidArgumentException('accepted_production_event_group_invalid'),
                );
                unset($candidates[$currentKey]);
                $currentReducer = null;
            }
            $currentKey = $key;
            $currentReducer ??= new AcceptedProductionEventReducer($candidates[$key]);
            $currentReducer->append($event);
        }
        if ($currentKey !== null) {
            $this->appendEntry(
                $entries,
                $currentReducer?->finish()
                    ?? throw new InvalidArgumentException('accepted_production_event_group_invalid'),
            );
            unset($candidates[$currentKey]);
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

    private function appendEntry(
        DeterministicObjectSpool $entries,
        AcceptedProductionUniverseEntry $entry,
    ): void {
        $entries->append(
            $entry,
            $entry->canonicalIdentity(),
        );
    }

    private function filters(ReportScope $scope, ReportQuery $query): array
    {
        $resources = new ReportScopedResourceFilter;
        $workIds = $this->intersect(
            $resources->ids($scope, ['work', 'completed_work'], $scope->projectIds),
            $this->positiveIntegerFilter($query, 'work_ids'),
        );
        $actIds = $this->intersect(
            $resources->ids($scope, ['act', 'performance_act', 'contract_performance_act'], $scope->projectIds),
            $this->positiveIntegerFilter($query, 'act_ids', 'performance_act_ids'),
        );

        return [
            array_values(array_intersect(
                $scope->projectIds,
                $this->positiveIntegerFilter($query, 'project_ids') ?? $scope->projectIds,
            )),
            $workIds,
            $actIds,
            $resources->ids($scope, ['act_line', 'performance_act_line'], $scope->projectIds),
        ];
    }

    private function ownerQuery(
        ReportScope $scope,
        ReportQuery $query,
        array $projectIds,
        ?array $actIds,
    ): Builder {
        return ProductionAcceptanceOwnerVersion::query()
            ->where('organization_id', $scope->organizationId)
            ->whereIn('project_id', $projectIds)
            ->where('effective_at', '<=', $query->asOf)
            ->whereRaw(
                'NOT EXISTS (
                    SELECT 1 FROM production_acceptance_owner_versions owner_later
                    WHERE owner_later.organization_id = production_acceptance_owner_versions.organization_id
                      AND owner_later.performance_act_id = production_acceptance_owner_versions.performance_act_id
                      AND owner_later.effective_at <= ?
                      AND (owner_later.effective_at > production_acceptance_owner_versions.effective_at
                        OR (owner_later.effective_at = production_acceptance_owner_versions.effective_at
                          AND owner_later.version > production_acceptance_owner_versions.version))
                )',
                [$query->asOf->format(DATE_ATOM)],
            )
            ->when(
                $actIds !== null,
                static fn (Builder $builder) => $builder->whereIn('performance_act_id', $actIds),
            );
    }

    private function legacyGapRows(
        ReportScope $scope,
        ReportQuery $query,
        array $projectIds,
        ?array $actIds,
    ): \Generator {
        $acts = ContractPerformanceAct::query()
            ->whereIn('project_id', $projectIds)
            ->whereHas('contract', static fn (Builder $builder) => $builder
                ->where('organization_id', $scope->organizationId))
            ->where(static function (Builder $builder) use ($query): void {
                $builder
                    ->where(static fn (Builder $signed) => $signed
                        ->whereNotNull('signed_at')
                        ->where('signed_at', '<=', $query->asOf))
                    ->orWhere(static fn (Builder $approved) => $approved
                        ->whereNotNull('approval_date')
                        ->where('approval_date', '<=', $query->asOf));
            })
            ->whereNotExists(static function ($builder) use ($scope, $query): void {
                $builder
                    ->selectRaw('1')
                    ->from('production_acceptance_owner_versions as owner_coverage')
                    ->whereColumn('owner_coverage.performance_act_id', 'contract_performance_acts.id')
                    ->where('owner_coverage.organization_id', $scope->organizationId)
                    ->where('owner_coverage.effective_at', '<=', $query->asOf);
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
            ->where('recognized_at', '<=', $query->asOf)
            ->where('status', 'unprovable')
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

    private function candidateMatchesState(array $candidate, ReportQuery $query): bool
    {
        [$from, $to] = $this->period($query->filters->values);
        $date = substr((string) $candidate['effective_at'], 0, 10);

        return $this->matches($query->filters->values['statuses'] ?? [], $candidate['event_type'])
            && ($from === null || $date >= $from)
            && ($to === null || $date <= $to);
    }

    private function eventMatchesState(ProductionAcceptanceEvent $event, ReportQuery $query): bool
    {
        [$from, $to] = $this->period($query->filters->values);
        $date = $event->recognized_at->format('Y-m-d');

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
        $period = $values['period'] ?? [];
        $from = $values['period_from'] ?? (is_array($period) ? ($period['from'] ?? null) : null);
        $to = $values['period_to'] ?? (is_array($period) ? ($period['to'] ?? null) : null);
        foreach ([$from, $to] as $date) {
            if ($date !== null
                && (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1)
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
        ?string $alias = null,
    ): ?array {
        $value = $query->filters->values[$key]
            ?? ($alias === null ? null : ($query->filters->values[$alias] ?? null));
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
        ]);
    }
}
