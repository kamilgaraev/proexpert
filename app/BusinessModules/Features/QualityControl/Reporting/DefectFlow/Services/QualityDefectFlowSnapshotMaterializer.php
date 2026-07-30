<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Services;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Core\Reporting\Support\ReportSnapshotFirstWriter;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models\QualityDefectFlowPolicyVersion;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models\QualityDefectFlowRow;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models\QualityDefectFlowSnapshot;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models\QualityDefectTransitionEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Jobs\ReportingSourceBackfillJob;
use Throwable;

final readonly class QualityDefectFlowSnapshotMaterializer
{
    public function __construct(private QualityDefectFlowFormula $formula) {}

    public function materialize(ReportExecutionContext $context, ReportQuery $query): QualityDefectFlowSnapshot
    {
        $organizationId = $context->scope->organizationId;
        ReportingSourceBackfillJob::dispatch($organizationId, ReportingSourceBackfillJob::QUALITY_DEFECTS);
        $asOf = CarbonImmutable::instance($query->asOf);
        [$periodFrom, $periodTo] = $this->period($query, $asOf);
        $events = $this->events(
            $organizationId,
            $context->scope->projectIds,
            $context->scope->resources,
            $query,
            $asOf,
        );
        $events = $this->filterSubjects($events, $query, $periodFrom, $periodTo);
        $projectIds = $events->pluck('project_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->merge($context->scope->projectIds)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $policies = $this->policies($organizationId, $projectIds, $asOf);
        $analysis = $this->analyze($events, $periodFrom, $periodTo, $asOf, $policies);
        $ownerTransitionCount = $this->ownerTransitionCount(
            $organizationId,
            $context->scope->projectIds,
            $context->scope->resources,
            $asOf,
            $query,
        );
        $analysis['gaps'] += max(0, $ownerTransitionCount - $events->count());
        $policyIds = collect($policies)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->unique()->sort()->values()->all();
        $inputHash = hash('sha256', CanonicalJson::encode([
            'event_hashes' => $events->pluck('event_hash')->all(),
            'filters' => $query->filters->values,
            'period_from' => $periodFrom->toAtomString(),
            'period_to' => $periodTo->toAtomString(),
            'policy_hashes' => collect($policies)->pluck('source_hash')->unique()->sort()->values()->all(),
        ]));
        $outputHash = hash('sha256', CanonicalJson::encode($analysis));
        $sourceHash = hash('sha256', CanonicalJson::encode([
            'input_hash' => $inputHash,
            'output_hash' => $outputHash,
            'query_hash' => $query->queryHash->value,
        ]));
        $scopeHash = hash('sha256', CanonicalJson::encode($context->scope->canonicalIdentity()));

        $existing = QualityDefectFlowSnapshot::query()
            ->where('organization_id', $organizationId)
            ->where('scope_hash', $scopeHash)
            ->where('as_of', $asOf)
            ->where('formula_version', $query->definition->formulaVersion)
            ->where('source_hash', $sourceHash)
            ->first();
        if ($existing instanceof QualityDefectFlowSnapshot) {
            return $existing;
        }

        return ReportSnapshotFirstWriter::run(
            'quality_defect_flow:'.$organizationId.':'.$scopeHash.':'.$sourceHash,
            function () use (
                $organizationId,
                $asOf,
                $policyIds,
                $events,
                $analysis,
                $inputHash,
                $outputHash,
                $sourceHash,
                $scopeHash,
                $query,
            ): QualityDefectFlowSnapshot {
                $existing = QualityDefectFlowSnapshot::query()
                    ->where('organization_id', $organizationId)
                    ->where('scope_hash', $scopeHash)
                    ->where('as_of', $asOf)
                    ->where('formula_version', $query->definition->formulaVersion)
                    ->where('source_hash', $sourceHash)
                    ->first();
                if ($existing instanceof QualityDefectFlowSnapshot) {
                    return $existing;
                }

                return DB::transaction(function () use (
            $organizationId,
            $asOf,
            $policyIds,
            $events,
            $analysis,
            $inputHash,
            $outputHash,
            $sourceHash,
            $scopeHash,
            $query,
        ): QualityDefectFlowSnapshot {
            $generatedAt = CarbonImmutable::now();
            $snapshot = QualityDefectFlowSnapshot::query()->create([
                'id' => (string) Str::ulid(),
                'organization_id' => $organizationId,
                'project_id' => count($query->scope->projectIds) === 1 ? $query->scope->projectIds[0] : null,
                'policy_version_ids' => $policyIds,
                'scope_hash' => $scopeHash,
                'definition_hash' => $query->definition->definitionHash->value,
                'formula_version' => $query->definition->formulaVersion,
                'query_hash' => $query->queryHash->value,
                'input_hash' => $inputHash,
                'output_hash' => $outputHash,
                'source_hash' => $sourceHash,
                'as_of' => $asOf,
                'source_watermark' => $events->max('occurred_at') ?? $asOf,
                'row_count' => count($analysis['rows']),
                'opening_count' => $analysis['opening'],
                'created_count' => $analysis['created'],
                'reopened_count' => $analysis['reopened'],
                'closed_count' => $analysis['closed'],
                'closing_count' => $analysis['closing'],
                'due_count' => $analysis['due_count'],
                'overdue_count' => $analysis['overdue_count'],
                'overdue_pct' => $analysis['overdue_pct'],
                'mature_cohort_count' => $analysis['mature_cohort_count'],
                'first_pass_count' => $analysis['first_pass_count'],
                'mature_reopened_count' => $analysis['mature_reopened_count'],
                'reopen_rate' => $analysis['reopen_rate'],
                'first_pass_yield' => $analysis['first_pass_yield'],
                'eligible_count' => count($analysis['rows']),
                'projected_count' => count($analysis['rows']),
                'gap_count' => $analysis['gaps'],
                'unknown_count' => $analysis['unknowns'],
                'generated_at' => $generatedAt,
                'stale_at' => $generatedAt->addDay(),
            ]);

            foreach ($analysis['rows'] as $row) {
                QualityDefectFlowRow::query()->create([
                    'organization_id' => $organizationId,
                    'snapshot_id' => $snapshot->id,
                ] + $row);
            }

                    return $snapshot;
                });
            },
        );
    }

    private function events(
        int $organizationId,
        array $scopeProjectIds,
        array $scopeResources,
        ReportQuery $query,
        CarbonImmutable $periodTo,
    ): Collection {
        $builder = QualityDefectTransitionEvent::query()
            ->where('organization_id', $organizationId)
            ->where('occurred_at', '<=', $periodTo)
            ->when($scopeProjectIds !== [], static fn (Builder $builder) => $builder->whereIn('project_id', $scopeProjectIds));
        $this->applyFilter($builder, 'project_id', $query->filters->values['project_id'] ?? null);
        $this->applyFilter($builder, 'contractor_id', $query->filters->values['contractor_id'] ?? null);
        $this->applyFilter(
            $builder,
            'schedule_task_id',
            $query->filters->values['schedule_task_id'] ?? $query->filters->values['task_id'] ?? null,
        );
        $this->applyFilter($builder, 'severity', $query->filters->values['severity'] ?? null);
        $this->applyResourceScope($builder, $scopeResources);

        return $builder
            ->orderBy('quality_defect_id')
            ->orderBy('event_version')
            ->orderBy('id')
            ->get();
    }

    private function filterSubjects(
        Collection $events,
        ReportQuery $query,
        CarbonImmutable $periodFrom,
        CarbonImmutable $periodTo,
    ): Collection {
        $statusFilter = $this->filterValues($query->filters->values['status'] ?? null);
        $cohortFrom = $this->optionalDate(
            $query->filters->values['cohort_from'] ?? null,
            $query,
            CarbonImmutable::create(1, 1, 1),
        );
        $cohortTo = $this->optionalDate(
            $query->filters->values['cohort_to'] ?? null,
            $query,
            CarbonImmutable::create(9999, 12, 31),
        );
        if ($cohortFrom > $cohortTo) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_RANGE_INVALID);
        }

        $allowed = [];
        foreach ($events->groupBy('quality_defect_id') as $defectId => $timeline) {
            $first = $timeline->first();
            $last = $timeline->last();
            $cohortAt = CarbonImmutable::instance($first->occurred_at);
            if ($cohortAt < $cohortFrom || $cohortAt > $cohortTo) {
                continue;
            }
            if ($statusFilter !== [] && ! in_array((string) $last->to_status, $statusFilter, true)) {
                continue;
            }
            $allowed[(int) $defectId] = true;
        }

        return $events
            ->filter(static fn (QualityDefectTransitionEvent $event): bool => isset($allowed[(int) $event->quality_defect_id]))
            ->values();
    }

    private function policies(int $organizationId, array $projectIds, CarbonImmutable $asOf): array
    {
        $keys = $projectIds === [] ? [0] : $projectIds;
        $policies = [];
        foreach ($keys as $projectId) {
            $policy = QualityDefectFlowPolicyVersion::query()
                ->where('organization_id', $organizationId)
                ->where('created_at', '<=', $asOf)
                ->whereDate('effective_from', '<=', $asOf->toDateString())
                ->where(static function (Builder $builder) use ($asOf): void {
                    $builder->whereNull('effective_until')
                        ->orWhereDate('effective_until', '>=', $asOf->toDateString());
                })
                ->where(static function (Builder $builder) use ($projectId): void {
                    $builder->whereNull('project_id');
                    if ($projectId > 0) {
                        $builder->orWhere('project_id', $projectId);
                    }
                })
                ->orderByRaw('project_id IS NULL')
                ->first();
            if (! $policy instanceof QualityDefectFlowPolicyVersion) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
            }
            if ($policy->calendar_code !== 'calendar_days'
                || ! is_array($policy->terminal_statuses)
                || $policy->terminal_statuses === []
                || array_filter($policy->terminal_statuses, 'is_string') !== $policy->terminal_statuses) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
            }
            $policies[$projectId] = $policy;
        }

        return $policies;
    }

    private function period(ReportQuery $query, CarbonImmutable $asOf): array
    {
        $periodFromValue = $query->filters->values['period_from'] ?? null;
        $periodToValue = $query->filters->values['period_to'] ?? null;
        $periodFrom = $this->optionalDate($periodFromValue, $query, $asOf->startOfMonth());
        $periodTo = $this->optionalDate($periodToValue, $query, $asOf);
        if (is_string($periodFromValue) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $periodFromValue) === 1) {
            $periodFrom = $periodFrom->startOfDay();
        }
        if (is_string($periodToValue) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $periodToValue) === 1) {
            $periodTo = $periodTo->endOfDay()->min($asOf);
        }
        if ($periodFrom > $periodTo || $periodTo > $asOf) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_RANGE_INVALID);
        }

        return [$periodFrom, $periodTo];
    }

    private function optionalDate(mixed $value, ReportQuery $query, CarbonImmutable $default): CarbonImmutable
    {
        if ($value === null) {
            return $default;
        }
        if (! is_string($value)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_RANGE_INVALID);
        }
        try {
            return CarbonImmutable::parse($value, $query->scope->timezone);
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_RANGE_INVALID, previous: $exception);
        }
    }

    private function analyze(
        Collection $events,
        CarbonImmutable $periodFrom,
        CarbonImmutable $periodTo,
        CarbonImmutable $asOf,
        array $policies,
    ): array {
        $rows = [];
        $states = [];
        $lastVersion = [];
        $lastStatus = [];
        $lastOccurredAt = [];
        $openingState = [];
        $created = 0;
        $reopened = 0;
        $closed = 0;
        $gaps = 0;
        $unknowns = 0;

        foreach ($events as $event) {
            $defectId = (int) $event->quality_defect_id;
            $policy = $policies[(int) $event->project_id] ?? null;
            if (! $policy instanceof QualityDefectFlowPolicyVersion) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
            }
            $occurredAt = CarbonImmutable::instance($event->occurred_at);
            $states[$defectId] ??= [
                'cohort_at' => $occurredAt,
                'current_open' => false,
                'due_date' => $event->due_date === null
                    ? $occurredAt->addDays((int) $policy->sla_days)->startOfDay()
                    : CarbonImmutable::instance($event->due_date),
                'ever_closed' => false,
                'reopened_after_closure' => false,
                'policy' => $policy,
            ];
            if ($states[$defectId]['due_date'] === null && $event->due_date !== null) {
                $states[$defectId]['due_date'] = CarbonImmutable::instance($event->due_date);
            }

            $expectedVersion = ($lastVersion[$defectId] ?? 0) + 1;
            if ((int) $event->event_version !== $expectedVersion
                || ((int) $event->event_version > 1 && $event->from_status !== ($lastStatus[$defectId] ?? null))
                || (isset($lastOccurredAt[$defectId]) && $occurredAt < $lastOccurredAt[$defectId])) {
                $gaps++;
            }
            $lastVersion[$defectId] = (int) $event->event_version;
            $lastStatus[$defectId] = (string) $event->to_status;
            $lastOccurredAt[$defectId] = $occurredAt;

            $wasOpen = $states[$defectId]['current_open'];
            $isClosed = $this->formula->isClosure($event, $policy);
            $isReopen = $this->formula->isReopen($event, $policy)
                && ! $wasOpen
                && $states[$defectId]['ever_closed'];
            if ($isReopen) {
                $states[$defectId]['reopened_after_closure'] = true;
            }
            $states[$defectId]['ever_closed'] = $states[$defectId]['ever_closed'] || $isClosed;
            $states[$defectId]['current_open'] = ! $isClosed;

            if ($occurredAt < $periodFrom) {
                $openingState[$defectId] = ! $isClosed;
                continue;
            }
            if ($occurredAt > $periodTo) {
                continue;
            }

            $createdFlag = $event->from_status === null;
            $created += $createdFlag ? 1 : 0;
            $reopened += $isReopen ? 1 : 0;
            $closed += $isClosed ? 1 : 0;
            if (in_array((string) $event->to_status, $policy->terminal_statuses, true) && ! $isClosed) {
                $unknowns++;
            }
            $rows[] = [
                'project_id' => (int) $event->project_id,
                'contractor_id' => $event->contractor_id === null ? null : (int) $event->contractor_id,
                'schedule_task_id' => $event->schedule_task_id === null ? null : (int) $event->schedule_task_id,
                'quality_defect_id' => $defectId,
                'event_version' => (int) $event->event_version,
                'row_key' => sprintf('defect:%d:event:%d', $defectId, $event->event_version),
                'cohort_date' => $states[$defectId]['cohort_at']->toDateString(),
                'severity' => (string) $event->severity,
                'status' => (string) $event->to_status,
                'opening_flag' => false,
                'created_flag' => $createdFlag,
                'reopened_flag' => $isReopen,
                'closed_flag' => $isClosed,
                'closing_flag' => false,
                'cohort_eligible' => $states[$defectId]['cohort_at']->addDays((int) $policy->maturity_days) <= $asOf,
                'cycle_days' => $isClosed ? $states[$defectId]['cohort_at']->diffInDays($occurredAt) : null,
                'due_date' => $states[$defectId]['due_date']->toDateString(),
                'evidence_refs' => $event->evidence_refs ?? [],
            ];
        }

        $opening = count(array_filter($openingState));
        $closing = $this->formula->rollForward($opening, $created, $reopened, $closed);
        $actualClosing = count(array_filter(
            $states,
            static fn (array $entityState): bool => (bool) $entityState['current_open'],
        ));
        if ($closing !== $actualClosing) {
            $gaps += abs($closing - $actualClosing);
        }

        $dueCount = 0;
        $overdueCount = 0;
        $matureCohortCount = 0;
        $firstPassCount = 0;
        $matureReopenedCount = 0;
        foreach ($states as $state) {
            if ($state['due_date'] !== null && $state['due_date']->startOfDay() <= $asOf->startOfDay()) {
                $dueCount++;
                if ($state['current_open'] && $state['due_date']->startOfDay() < $asOf->startOfDay()) {
                    $overdueCount++;
                }
            }
            if ($state['cohort_at']->addDays((int) $state['policy']->maturity_days) <= $asOf) {
                $matureCohortCount++;
                if ($state['reopened_after_closure']) {
                    $matureReopenedCount++;
                }
                if ($state['ever_closed'] && ! $state['reopened_after_closure']) {
                    $firstPassCount++;
                }
            }
        }

        $lastRowByDefect = [];
        foreach ($rows as $index => $row) {
            $lastRowByDefect[$row['quality_defect_id']] = $index;
        }
        foreach ($lastRowByDefect as $defectId => $index) {
            $rows[$index]['closing_flag'] = (bool) ($states[$defectId]['current_open'] ?? false);
            $rows[$index]['opening_flag'] = $openingState[$defectId] ?? false;
        }

        return [
            'rows' => $rows,
            'opening' => $opening,
            'created' => $created,
            'reopened' => $reopened,
            'closed' => $closed,
            'closing' => $closing,
            'due_count' => $dueCount,
            'overdue_count' => $overdueCount,
            'overdue_pct' => $this->formula->percentage($overdueCount, $dueCount),
            'mature_cohort_count' => $matureCohortCount,
            'first_pass_count' => $firstPassCount,
            'mature_reopened_count' => $matureReopenedCount,
            'reopen_rate' => $this->formula->percentage($matureReopenedCount, $matureCohortCount),
            'first_pass_yield' => $this->formula->percentage($firstPassCount, $matureCohortCount),
            'gaps' => $gaps,
            'unknowns' => $unknowns,
        ];
    }

    private function applyFilter(Builder $builder, string $column, mixed $value): void
    {
        $values = $this->filterValues($value);
        if ($values !== []) {
            $builder->whereIn($column, $values);
        }
    }

    private function ownerTransitionCount(
        int $organizationId,
        array $projectIds,
        array $resources,
        CarbonImmutable $asOf,
        ReportQuery $query,
    ): int {
        $builder = DB::table('quality_defect_status_history as history')
            ->join('quality_defects as defect', 'defect.id', '=', 'history.quality_defect_id')
            ->where('history.organization_id', $organizationId)
            ->where('history.changed_at', '<=', $asOf);
        if ($projectIds !== []) {
            $builder->whereIn('defect.project_id', $projectIds);
        }
        if ($resources !== []) {
            $builder->where(function ($builder) use ($resources): void {
                foreach ($resources as $resource) {
                    if (! $resource instanceof ReportScopedResource) {
                        continue;
                    }
                    $builder->orWhere(function ($builder) use ($resource): void {
                        match ($resource->kind) {
                            'project' => $builder->where('defect.project_id', $resource->id),
                            'contractor' => $builder->where('defect.contractor_id', $resource->id),
                            'quality_defect' => $builder->where('defect.id', $resource->id),
                            'schedule_task', 'task' => $builder->where('defect.schedule_task_id', $resource->id),
                            default => $builder->whereRaw('1 = 0'),
                        };
                        if ($resource->projectId !== null) {
                            $builder->where('defect.project_id', $resource->projectId);
                        }
                    });
                }
            });
        }
        foreach ([
            'contractor_id' => 'defect.contractor_id',
            'schedule_task_id' => 'defect.schedule_task_id',
            'task_id' => 'defect.schedule_task_id',
            'severity' => 'defect.severity',
        ] as $filter => $column) {
            $values = $this->filterValues($query->filters->values[$filter] ?? null);
            if ($values !== []) {
                $builder->whereIn($column, $values);
            }
        }
        $statuses = $this->filterValues($query->filters->values['status'] ?? null);
        if ($statuses !== []) {
            $placeholders = implode(',', array_fill(0, count($statuses), '?'));
            $builder->whereRaw(
                '(SELECT latest.to_status FROM quality_defect_status_history latest'
                .' WHERE latest.organization_id = history.organization_id'
                .' AND latest.quality_defect_id = history.quality_defect_id'
                .' AND latest.changed_at <= ? ORDER BY latest.changed_at DESC, latest.id DESC LIMIT 1)'
                ." IN ($placeholders)",
                [$asOf, ...$statuses],
            );
        }
        $cohortFrom = $this->filterValues($query->filters->values['cohort_from'] ?? null);
        $cohortTo = $this->filterValues($query->filters->values['cohort_to'] ?? null);
        if ($cohortFrom !== []) {
            $builder->whereRaw(
                '(SELECT MIN(first_history.changed_at) FROM quality_defect_status_history first_history'
                .' WHERE first_history.organization_id = history.organization_id'
                .' AND first_history.quality_defect_id = history.quality_defect_id) >= ?',
                [(string) $cohortFrom[0]],
            );
        }
        if ($cohortTo !== []) {
            $builder->whereRaw(
                '(SELECT MIN(first_history.changed_at) FROM quality_defect_status_history first_history'
                .' WHERE first_history.organization_id = history.organization_id'
                .' AND first_history.quality_defect_id = history.quality_defect_id) <= ?',
                [(string) $cohortTo[0]],
            );
        }

        return $builder->count();
    }

    private function applyResourceScope(Builder $builder, array $resources): void
    {
        if ($resources === []) {
            return;
        }

        $supported = array_values(array_filter(
            $resources,
            static fn (mixed $resource): bool => $resource instanceof ReportScopedResource
                && in_array($resource->kind, [
                    'contractor',
                    'project',
                    'quality_defect',
                    'schedule_task',
                    'task',
                ], true),
        ));
        if ($supported === []) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where(static function (Builder $builder) use ($supported): void {
            foreach ($supported as $resource) {
                $builder->orWhere(static function (Builder $builder) use ($resource): void {
                    match ($resource->kind) {
                        'contractor' => $builder->where('contractor_id', $resource->id),
                        'project' => $builder->where('project_id', $resource->id),
                        'quality_defect' => $builder->where('quality_defect_id', $resource->id),
                        'schedule_task', 'task' => $builder->where('schedule_task_id', $resource->id),
                    };
                    if ($resource->projectId !== null) {
                        $builder->where('project_id', $resource->projectId);
                    }
                });
            }
        });
    }

    private function filterValues(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return array_values(array_filter(
            is_array($value) ? $value : [$value],
            static fn (mixed $item): bool => is_int($item) || is_string($item),
        ));
    }

}
