<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\Models\ContractPerformanceAct;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceBackfillLedger;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceOwnerVersion;
use App\Support\Reporting\ReportScopedResourceFilter;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final readonly class AcceptedProductionEventUniverse
{
    public function resolve(ReportScope $scope, ReportQuery $query): array
    {
        $resources = new ReportScopedResourceFilter;
        $workIds = $this->intersect(
            $resources->ids($scope, ['work', 'completed_work'], $scope->projectIds),
            $this->positiveIntegerFilter($query, 'work_ids'),
        );
        $actIds = $this->intersect(
            $resources->ids(
                $scope,
                ['act', 'performance_act', 'contract_performance_act'],
                $scope->projectIds,
            ),
            $this->positiveIntegerFilter($query, 'act_ids', 'performance_act_ids'),
        );
        $actLineIds = $resources->ids(
            $scope,
            ['act_line', 'performance_act_line'],
            $scope->projectIds,
        );
        $projectIds = array_values(array_intersect(
            $scope->projectIds,
            $this->positiveIntegerFilter($query, 'project_ids') ?? $scope->projectIds,
        ));

        $candidateKeys = [];
        $candidates = [];
        $ownerQuery = ProductionAcceptanceOwnerVersion::query()
            ->where('organization_id', $scope->organizationId)
            ->whereIn('project_id', $projectIds)
            ->where('effective_at', '<=', $query->asOf)
            ->whereRaw(
                'NOT EXISTS (
                    SELECT 1
                    FROM production_acceptance_owner_versions owner_later
                    WHERE owner_later.organization_id = production_acceptance_owner_versions.organization_id
                      AND owner_later.performance_act_id = production_acceptance_owner_versions.performance_act_id
                      AND owner_later.effective_at <= ?
                      AND (
                          owner_later.effective_at > production_acceptance_owner_versions.effective_at
                          OR (
                              owner_later.effective_at = production_acceptance_owner_versions.effective_at
                              AND owner_later.version > production_acceptance_owner_versions.version
                          )
                      )
                )',
                [$query->asOf->format(DATE_ATOM)],
            )
            ->when(
                $actIds !== null,
                static fn (Builder $builder) => $builder->whereIn('performance_act_id', $actIds),
            );
        foreach ($ownerQuery->lazyById(500) as $owner) {
            foreach ((array) $owner->members as $member) {
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
                $candidateKeys[$key] = true;
                $candidates[$key] = $candidate;
            }
        }
        $legacyGaps = [];
        $legacyQuery = ContractPerformanceAct::query()
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
                    ->whereColumn(
                        'owner_coverage.performance_act_id',
                        'contract_performance_acts.id',
                    )
                    ->where('owner_coverage.organization_id', $scope->organizationId)
                    ->where('owner_coverage.effective_at', '<=', $query->asOf);
            })
            ->when(
                $actIds !== null,
                static fn (Builder $builder) => $builder->whereIn('id', $actIds),
            );
        foreach ($legacyQuery->lazyById(500) as $act) {
            $legacyGaps['act:'.(int) $act->id] = [
                'performance_act_id' => (int) $act->id,
                'project_id' => (int) $act->project_id,
                'reason' => 'historical_membership_unprovable',
            ];
        }
        $ledgerQuery = ProductionAcceptanceBackfillLedger::query()
            ->where('organization_id', $scope->organizationId)
            ->whereIn('project_id', $projectIds)
            ->where('recognized_at', '<=', $query->asOf)
            ->where('status', 'unprovable')
            ->when(
                $actIds !== null,
                static fn (Builder $builder) => $builder->whereIn('performance_act_id', $actIds),
            );
        foreach ($ledgerQuery->lazyById(500) as $ledger) {
            $legacyGaps['act:'.(int) $ledger->performance_act_id] = [
                'ledger_id' => (int) $ledger->id,
                'performance_act_id' => (int) $ledger->performance_act_id,
                'project_id' => (int) $ledger->project_id,
                'reason' => (string) $ledger->reason,
            ];
        }

        $events = collect();
        $orphanEvents = [];
        $eventQuery = ProductionAcceptanceEvent::query()
            ->where('organization_id', $scope->organizationId)
            ->whereIn('project_id', $projectIds)
            ->where('recognized_at', '<=', $query->asOf);
        $this->applyEventScalarFilters(
            $eventQuery,
            $query,
            $workIds,
            $actIds,
            $actLineIds,
        );
        foreach ($eventQuery->lazyById(500) as $event) {
            $key = $this->key([
                'performance_act_id' => (int) $event->performance_act_id,
                'source_line_id' => (int) $event->source_line_id,
                'source_line_type' => (string) $event->source_line_type,
            ]);
            if (isset($candidateKeys[$key])) {
                $events->push($event);
            } else {
                $orphanEvents[(int) $event->id] = [
                    'event_id' => (int) $event->id,
                    'event_type' => (string) $event->event_type,
                    'performance_act_id' => (int) $event->performance_act_id,
                    'recognized_at' => $event->recognized_at->format(DATE_ATOM),
                    'source_line_id' => (int) $event->source_line_id,
                    'source_line_type' => (string) $event->source_line_type,
                ];
            }
        }
        $events = $events
            ->sortBy([
                ['performance_act_id', 'asc'],
                ['source_line_type', 'asc'],
                ['source_line_id', 'asc'],
                ['transition_version', 'asc'],
            ])
            ->values();
        [$candidates, $events, $orphanEvents] = $this->applyStateFilters(
            $candidates,
            $events,
            $orphanEvents,
            $query,
        );

        return [
            'candidates' => array_values($candidates),
            'events' => $events,
            'legacy_gaps' => array_values($legacyGaps),
            'orphan_events' => array_values($orphanEvents),
            'project_ids' => $projectIds,
        ];
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

    private function applyStateFilters(
        array $candidates,
        \Illuminate\Support\Collection $events,
        array $orphanEvents,
        ReportQuery $query,
    ): array {
        $values = $query->filters->values;
        [$from, $to] = $this->period($values);
        $statuses = $values['statuses'] ?? [];
        $keptKeys = [];
        $candidates = array_filter(
            $candidates,
            function (array $candidate) use ($statuses, $from, $to, &$keptKeys): bool {
                $date = substr((string) $candidate['effective_at'], 0, 10);
                $keep = $this->matches($statuses, $candidate['event_type'])
                    && ($from === null || $date >= $from)
                    && ($to === null || $date <= $to);
                if ($keep) {
                    $keptKeys[$this->key($candidate)] = true;
                }

                return $keep;
            },
        );
        $events = $events
            ->filter(function (ProductionAcceptanceEvent $event) use (
                $keptKeys,
                $statuses,
                $from,
                $to,
            ): bool {
                $date = $event->recognized_at->format('Y-m-d');

                return isset($keptKeys[$this->key([
                    'performance_act_id' => (int) $event->performance_act_id,
                    'source_line_id' => (int) $event->source_line_id,
                    'source_line_type' => (string) $event->source_line_type,
                ])])
                    && $this->matches($statuses, (string) $event->event_type)
                    && ($from === null || $date >= $from)
                    && ($to === null || $date <= $to);
            })
            ->values();
        $orphanEvents = array_filter(
            $orphanEvents,
            function (array $event) use ($statuses, $from, $to): bool {
                $date = substr((string) $event['recognized_at'], 0, 10);

                return $this->matches($statuses, $event['event_type'])
                    && ($from === null || $date >= $from)
                    && ($to === null || $date <= $to);
            },
        );

        return [$candidates, $events, $orphanEvents];
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
