<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Services;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportSnapshotSealStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Core\Reporting\Support\CompletedReportSourceLedgerBinding;
use App\BusinessModules\Core\Reporting\Support\ReportSnapshotFirstWriter;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\DTO\SafetyTransitionFact;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetyExposureDay;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetyIncidentPolicyVersion;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetyIncidentRow;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetyIncidentSnapshot;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetySite;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetyTransitionEvent;
use App\Jobs\ReportingSourceBackfillJob;
use App\Jobs\SafetyExposureZeroFillJob;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class SafetyIncidentSnapshotMaterializer
{
    public function __construct(
        private SafetyIncidentFormula $formula,
        private ReportSnapshotSealStore $seals,
    ) {}

    public function materialize(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
    ): SafetyIncidentSnapshot {
        $organizationId = $context->scope->organizationId;
        ReportingSourceBackfillJob::request($organizationId, ReportingSourceBackfillJob::SAFETY_INCIDENTS);
        ReportingSourceBackfillJob::request($organizationId, ReportingSourceBackfillJob::SAFETY_EXPOSURE);
        $ledgerBinding = CompletedReportSourceLedgerBinding::captureWithDocumentedGaps(
            $organizationId,
            [
                ReportingSourceBackfillJob::SAFETY_INCIDENTS,
                ReportingSourceBackfillJob::SAFETY_EXPOSURE,
            ],
        );
        $progress->advance(5);

        return ReportSnapshotFirstWriter::run(
            'safety_incident_actions:'.$organizationId.':'.$query->definition->definitionHash->value
            .':'.$query->queryHash->value.':'.$query->asOf->format(DATE_ATOM),
            fn (): SafetyIncidentSnapshot => $this->materializeLocked($context, $query, $ledgerBinding, $progress),
        );
    }

    private function materializeLocked(
        ReportExecutionContext $context,
        ReportQuery $query,
        array $ledgerBinding,
        ReportProgress $progress,
    ): SafetyIncidentSnapshot {
        $organizationId = $context->scope->organizationId;
        $this->assertPublicFilterValues($query);
        CompletedReportSourceLedgerBinding::lockAndAssertOwnerGeneration($organizationId, $ledgerBinding);
        $asOf = CarbonImmutable::instance($query->asOf);
        [$periodFrom, $periodTo] = $this->period($query, $asOf);
        SafetyExposureZeroFillJob::dispatch(
            $organizationId,
            $periodFrom->toDateString(),
            $periodTo->toDateString(),
        );
        $events = $this->events(
            $organizationId,
            $context->scope->projectIds,
            $context->scope->resources,
            $query,
            $asOf,
        );
        $events = $this->filterSubjects($events, $query, $periodFrom, $periodTo);
        $progress->advance(20);
        $projectIds = $events->pluck('project_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $policies = $this->policies($organizationId, $projectIds, $asOf);
        $policyIds = collect($policies)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->unique()->sort()->values()->all();
        $siteIds = $events->pluck('safety_site_id')->filter()->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $sites = SafetySite::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $siteIds)
            ->get()
            ->keyBy('id');
        $exposure = $this->exposure(
            $organizationId,
            $context->scope->projectIds,
            $context->scope->resources,
            $query,
            $periodFrom,
            $periodTo,
        );
        $analysis = $this->analyze($events, $periodFrom, $periodTo, $asOf, $policies, $sites, $progress);
        $ownerSubjectCount = $this->ownerSubjectCount(
            $organizationId,
            $context->scope->projectIds,
            $context->scope->resources,
            $asOf,
            $query,
        );
        $projectedSubjectCount = $events
            ->map(static fn (SafetyTransitionEvent $event): string => $event->subject_type.':'.$event->subject_id)
            ->unique()
            ->count();
        $analysis['gaps'] += max(0, $ownerSubjectCount - $projectedSubjectCount);
        $coverage = $this->exposureCoverage(
            $organizationId,
            $context->scope->projectIds,
            $query,
            $periodFrom,
            $periodTo,
            $exposure,
            $context->scope->resources,
        );
        $missingExposureDays = max(0, $coverage['expected_days'] - $coverage['projected_days']);
        $analysis['gaps'] += $missingExposureDays;
        $analysis['unknowns'] += $missingExposureDays;
        $progress->advance(85);
        $multipliers = collect($policies)->pluck('frequency_multiplier')->map(static fn (mixed $value): int => (int) $value)->unique();
        $emptyPolicyIndependent = $policies === [] && $analysis['rows'] === [];
        if (! $emptyPolicyIndependent && $multipliers->count() !== 1) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }
        $qualifying = $emptyPolicyIndependent
            ? 0
            : count(array_filter(
                $analysis['rows'],
                static function (array $row) use ($policies): bool {
                    $policy = $policies[$row['project_id']] ?? null;

                    return $policy instanceof SafetyIncidentPolicyVersion
                        && $row['subject_type'] === 'incident'
                        && $row['created_flag']
                        && in_array($row['category'], $policy->qualifying_incident_types, true);
                },
            ));
        $frequency = $coverage['complete']
            ? $this->formula->frequency(
                $qualifying,
                $coverage['hours'],
                $emptyPolicyIndependent ? 1 : (int) $multipliers->first(),
            )
            : null;
        $analysis['exposure'] = $coverage;
        $analysis['incident_frequency'] = $frequency;
        $inputHash = hash('sha256', CanonicalJson::encode([
            'event_hashes' => $events->pluck('event_hash')->all(),
            'exposure_hashes' => $exposure->pluck('source_hash')->all(),
            'filters' => $query->filters->values,
            'period_from' => $periodFrom->toAtomString(),
            'period_to' => $periodTo->toAtomString(),
            'policy_hashes' => collect($policies)->pluck('source_hash')->unique()->sort()->values()->all(),
            'source_ledger_binding' => $ledgerBinding,
        ]));
        $outputHash = hash('sha256', CanonicalJson::encode($analysis));
        $sourceHash = hash('sha256', CanonicalJson::encode([
            'input_hash' => $inputHash,
            'output_hash' => $outputHash,
            'query_hash' => $query->queryHash->value,
        ]));
        $scopeHash = hash('sha256', CanonicalJson::encode($context->scope->canonicalIdentity()));
        $existing = SafetyIncidentSnapshot::query()
            ->where('organization_id', $organizationId)
            ->where('scope_hash', $scopeHash)
            ->where('as_of', $asOf)
            ->where('formula_version', $query->definition->formulaVersion)
            ->where('source_hash', $sourceHash)
            ->first();
        if ($existing instanceof SafetyIncidentSnapshot) {
            return $existing;
        }

        return ReportSnapshotFirstWriter::run(
            'safety_incident_actions:'.$organizationId.':'.$scopeHash.':'.$sourceHash,
            function () use (
                $query,
                $organizationId,
                $asOf,
                $policyIds,
                $analysis,
                $coverage,
                $frequency,
                $inputHash,
                $ledgerBinding,
                $outputHash,
                $progress,
                $sourceHash,
                $scopeHash,
            ): SafetyIncidentSnapshot {
                $existing = SafetyIncidentSnapshot::query()
                    ->where('organization_id', $organizationId)
                    ->where('scope_hash', $scopeHash)
                    ->where('as_of', $asOf)
                    ->where('formula_version', $query->definition->formulaVersion)
                    ->where('source_hash', $sourceHash)
                    ->first();
                if ($existing instanceof SafetyIncidentSnapshot) {
                    return $existing;
                }

                return DB::transaction(function () use (
                    $query,
                    $organizationId,
                    $asOf,
                    $policyIds,
                    $analysis,
                    $coverage,
                    $frequency,
                    $inputHash,
                    $ledgerBinding,
                    $outputHash,
                    $progress,
                    $sourceHash,
                    $scopeHash,
                ): SafetyIncidentSnapshot {
                    $generatedAt = CarbonImmutable::now();
                    $snapshot = SafetyIncidentSnapshot::query()->create([
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
                        'source_watermark' => CarbonImmutable::parse((string) $ledgerBinding['watermark']),
                        'source_ledger_binding' => $ledgerBinding,
                        'row_count' => count($analysis['rows']),
                        'incident_count' => $analysis['incident_count'],
                        'violation_count' => $analysis['violation_count'],
                        'action_due_count' => $analysis['action_due_count'],
                        'action_overdue_count' => $analysis['action_overdue_count'],
                        'action_closed_on_time_count' => $analysis['action_closed_on_time_count'],
                        'opening_backlog_count' => $analysis['opening_backlog_count'],
                        'closing_backlog_count' => $analysis['closing_backlog_count'],
                        'exposure_hours' => $coverage['complete'] ? $coverage['hours'] : null,
                        'exposure_complete' => $coverage['complete'],
                        'incident_frequency' => $frequency,
                        'eligible_count' => count($analysis['rows']),
                        'projected_count' => count($analysis['rows']),
                        'gap_count' => $analysis['gaps'],
                        'unknown_count' => $analysis['unknowns'],
                        'generated_at' => $generatedAt,
                        'stale_at' => $generatedAt->addDay(),
                    ]);
                    $rowCount = count($analysis['rows']);
                    foreach ($analysis['rows'] as $rowIndex => $row) {
                        $progress->advanceProportion($rowIndex, $rowCount, 90, 98);
                        SafetyIncidentRow::query()->create([
                            'organization_id' => $organizationId,
                            'snapshot_id' => $snapshot->id,
                        ] + $row);
                    }
                    DB::table('safety_incident_snapshots')
                        ->where('id', $snapshot->id)
                        ->update([
                            'sealed_at' => $generatedAt,
                            'output_hash' => DB::raw("reporting_persisted_rows_digest('safety_incident_rows', id::text)"),
                            'sealed_content_digest' => DB::raw("reporting_persisted_rows_digest('safety_incident_rows', id::text)"),
                        ]);
                    $snapshot->output_hash = (string) DB::table('safety_incident_snapshots')
                        ->where('id', $snapshot->id)->value('output_hash');
                    $snapshot->sealed_at = $generatedAt;
                    if ($query->definition->snapshotClassification === ReportSnapshotClassification::OFFICIAL) {
                        $this->seals->create(
                            'safety_incident_actions',
                            (string) $snapshot->id,
                            $generatedAt->toDateTimeImmutable(),
                            new Sha256Hash((string) $snapshot->source_hash),
                            $generatedAt->toDateTimeImmutable(),
                        );
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
        $builder = SafetyTransitionEvent::query()
            ->where('organization_id', $organizationId)
            ->where('occurred_at', '<=', $periodTo)
            ->when($scopeProjectIds !== [], static fn (Builder $builder) => $builder->whereIn('project_id', $scopeProjectIds));
        $this->applyFilter($builder, 'project_id', $query->filters->values['project_id'] ?? null);
        $this->applyFilter($builder, 'safety_site_id', $query->filters->values['safety_site_id'] ?? $query->filters->values['site_id'] ?? null);
        $this->applyFilter($builder, 'contractor_id', $query->filters->values['contractor_id'] ?? null);
        $this->applyFilter($builder, 'subject_type', $query->filters->values['subject_type'] ?? null);
        $this->applyFilter($builder, 'category', $query->filters->values['category'] ?? null);
        $this->applyFilter($builder, 'severity', $query->filters->values['severity'] ?? null);
        $this->applyFilter($builder, 'owner_user_id', $query->filters->values['owner_user_id'] ?? null);
        $this->applyResourceScope($builder, $scopeResources);

        return $builder
            ->orderBy('subject_type')
            ->orderBy('subject_id')
            ->orderBy('event_version')
            ->orderBy('id')
            ->get();
    }

    private function exposure(
        int $organizationId,
        array $scopeProjectIds,
        array $scopeResources,
        ReportQuery $query,
        CarbonImmutable $periodFrom,
        CarbonImmutable $periodTo,
    ): Collection {
        $builder = SafetyExposureDay::query()
            ->where('organization_id', $organizationId)
            ->whereBetween('exposure_date', [$periodFrom->toDateString(), $periodTo->toDateString()])
            ->when($scopeProjectIds !== [], static fn (Builder $builder) => $builder->whereIn('project_id', $scopeProjectIds));
        $this->applyFilter($builder, 'project_id', $query->filters->values['project_id'] ?? null);
        $this->applyFilter($builder, 'safety_site_id', $query->filters->values['safety_site_id'] ?? $query->filters->values['site_id'] ?? null);
        $this->applyExposureResourceScope($builder, $scopeResources);

        return $builder->orderBy('safety_site_id')->orderBy('exposure_date')->get();
    }

    private function filterSubjects(
        Collection $events,
        ReportQuery $query,
        CarbonImmutable $periodFrom,
        CarbonImmutable $periodTo,
    ): Collection {
        $statusFilter = $this->filterValues($query->filters->values['status'] ?? null);
        $dueFrom = $this->optionalDate($query->filters->values['due_from'] ?? null, $query, CarbonImmutable::create(1, 1, 1));
        $dueTo = $this->optionalDate($query->filters->values['due_to'] ?? null, $query, CarbonImmutable::create(9999, 12, 31));
        if ($dueFrom > $dueTo) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_RANGE_INVALID);
        }

        $allowed = [];
        foreach ($events->groupBy(static fn (SafetyTransitionEvent $event): string => $event->subject_type.':'.$event->subject_id) as $key => $timeline) {
            $last = $timeline->last();
            $dueDate = $timeline->pluck('due_date')->filter()->last();
            if ($statusFilter !== [] && ! in_array((string) $last->to_status, $statusFilter, true)) {
                continue;
            }
            if ($dueDate !== null) {
                $dueDate = CarbonImmutable::instance($dueDate);
                if ($dueDate < $dueFrom || $dueDate > $dueTo) {
                    continue;
                }
            } elseif (($query->filters->values['due_from'] ?? null) !== null || ($query->filters->values['due_to'] ?? null) !== null) {
                continue;
            }
            $allowed[$key] = true;
        }

        return $events
            ->filter(static fn (SafetyTransitionEvent $event): bool => isset($allowed[$event->subject_type.':'.$event->subject_id]))
            ->values();
    }

    private function policies(int $organizationId, array $projectIds, CarbonImmutable $asOf): array
    {
        $policies = [];
        foreach ($projectIds as $projectId) {
            $policy = SafetyIncidentPolicyVersion::query()
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
            if (! $policy instanceof SafetyIncidentPolicyVersion) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
            }
            $statusesByType = $policy->terminal_statuses;
            if ($policy->calendar_code !== 'calendar_days'
                || $policy->overdue_rule !== 'site_local_end_of_day'
                || ! is_array($policy->qualifying_incident_types)
                || $policy->qualifying_incident_types === []
                || array_filter($policy->qualifying_incident_types, 'is_string') !== $policy->qualifying_incident_types
                || (int) $policy->frequency_multiplier < 1
                || ! is_array($statusesByType)) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
            }
            foreach (['incident', 'violation', 'corrective_action'] as $subjectType) {
                $statuses = $statusesByType[$subjectType] ?? null;
                if (! is_array($statuses) || $statuses === [] || array_filter($statuses, 'is_string') !== $statuses) {
                    throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
                }
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
        Collection $sites,
        ReportProgress $progress,
    ): array {
        $rows = [];
        $lastVersion = [];
        $lastStatus = [];
        $lastOccurredAt = [];
        $states = [];
        $openingState = [];
        $gaps = 0;
        $unknowns = 0;
        $incidentSubjects = [];
        $violationSubjects = [];

        $eventCount = $events->count();
        foreach ($events as $eventIndex => $event) {
            $progress->advanceProportion($eventIndex, $eventCount, 20, 75);
            $policy = $policies[(int) $event->project_id] ?? null;
            if (! $policy instanceof SafetyIncidentPolicyVersion) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
            }
            $key = $event->subject_type.':'.$event->subject_id;
            $occurredAt = CarbonImmutable::instance($event->occurred_at);
            $states[$key] ??= [
                'first_at' => $occurredAt,
                'current_open' => false,
                'due_date' => $event->due_date === null ? null : CarbonImmutable::instance($event->due_date),
                'closed_on_time' => false,
                'site_id' => $event->safety_site_id === null ? null : (int) $event->safety_site_id,
            ];
            if ($states[$key]['due_date'] === null && $event->due_date !== null) {
                $states[$key]['due_date'] = CarbonImmutable::instance($event->due_date);
            }
            if ($states[$key]['site_id'] === null && $event->safety_site_id !== null) {
                $states[$key]['site_id'] = (int) $event->safety_site_id;
            }

            $expectedVersion = ($lastVersion[$key] ?? 0) + 1;
            if ((int) $event->event_version !== $expectedVersion
                || ((int) $event->event_version > 1 && $event->from_status !== ($lastStatus[$key] ?? null))
                || (isset($lastOccurredAt[$key]) && $occurredAt < $lastOccurredAt[$key])) {
                $gaps++;
            }
            $lastVersion[$key] = (int) $event->event_version;
            $lastStatus[$key] = (string) $event->to_status;
            $lastOccurredAt[$key] = $occurredAt;

            $fact = new SafetyTransitionFact(
                subjectType: (string) $event->subject_type,
                subjectId: (int) $event->subject_id,
                fromStatus: $event->from_status,
                toStatus: (string) $event->to_status,
                occurredAt: $occurredAt->toDateTimeImmutable(),
                dueAt: $event->due_date?->toDateTimeImmutable(),
                resolvedAt: $event->resolved_at?->toDateTimeImmutable(),
                verifiedAt: $event->verified_at?->toDateTimeImmutable(),
                evidenceId: $event->evidence_id,
            );
            $wasOpen = $states[$key]['current_open'];
            $closureVerified = $this->formula->isClosure($fact, $policy);
            $reopened = $this->formula->isReopen($fact, $policy) && ! $wasOpen;
            $states[$key]['current_open'] = ! $closureVerified;
            if ($event->safety_site_id === null) {
                $unknowns++;
            }
            if ($this->isConfiguredTerminal($event, $policy) && ! $closureVerified) {
                $unknowns++;
            }
            if ($closureVerified && $states[$key]['due_date'] !== null) {
                $timezone = $this->timezone($states[$key]['site_id'], $sites);
                if ($timezone !== null) {
                    $closureAt = $event->verified_at === null
                        ? $occurredAt
                        : CarbonImmutable::instance($event->verified_at);
                    $states[$key]['closed_on_time'] = $closureAt->setTimezone($timezone)->startOfDay()
                        <= $states[$key]['due_date']->startOfDay();
                }
            }
            if ($occurredAt < $periodFrom) {
                $openingState[$key] = ! $closureVerified;

                continue;
            }
            if ($occurredAt > $periodTo) {
                continue;
            }

            $created = $event->from_status === null;
            if ($created && $event->subject_type === 'incident') {
                $incidentSubjects[(int) $event->subject_id] = true;
            }
            if ($created && $event->subject_type === 'violation') {
                $violationSubjects[(int) $event->subject_id] = true;
            }
            $siteTimezone = $this->timezone($states[$key]['site_id'], $sites);
            $rows[] = [
                'project_id' => (int) $event->project_id,
                'safety_site_id' => $event->safety_site_id === null ? null : (int) $event->safety_site_id,
                'contractor_id' => $event->contractor_id === null ? null : (int) $event->contractor_id,
                'subject_type' => (string) $event->subject_type,
                'subject_id' => (int) $event->subject_id,
                'event_version' => (int) $event->event_version,
                'row_key' => sprintf('%s:%d:event:%d', $event->subject_type, $event->subject_id, $event->event_version),
                'event_date' => ($siteTimezone === null
                    ? $occurredAt
                    : $occurredAt->setTimezone($siteTimezone))->toDateString(),
                'category' => $event->category,
                'severity' => (string) $event->severity,
                'status' => (string) $event->to_status,
                'owner_user_id' => $event->owner_user_id === null ? null : (int) $event->owner_user_id,
                'due_date' => $event->due_date?->toDateString(),
                'opening_flag' => false,
                'created_flag' => $created,
                'reopened_flag' => $reopened,
                'closed_flag' => $closureVerified,
                'closing_flag' => false,
                'closure_verified' => $closureVerified,
                'closure_days' => $closureVerified ? $states[$key]['first_at']->diffInDays($occurredAt) : null,
                'evidence_type' => $event->evidence_type,
                'evidence_id' => $event->evidence_id,
            ];
        }

        $actionDue = 0;
        $actionOverdue = 0;
        $actionClosedOnTime = 0;
        $stateCount = count($states);
        $completedStates = 0;
        foreach ($states as $key => $state) {
            $progress->advanceProportion($completedStates, $stateCount, 75, 82);
            $completedStates++;
            if (! str_starts_with($key, 'corrective_action:') || $state['due_date'] === null) {
                continue;
            }
            $timezone = $this->timezone($state['site_id'], $sites);
            if ($timezone === null) {
                continue;
            }
            $localAsOf = $asOf->setTimezone($timezone)->startOfDay();
            if ($state['due_date']->startOfDay() <= $localAsOf) {
                $actionDue++;
                if ($state['current_open'] && $state['due_date']->startOfDay() < $localAsOf) {
                    $actionOverdue++;
                }
                if (! $state['current_open'] && $state['closed_on_time']) {
                    $actionClosedOnTime++;
                }
            }
        }

        $lastRow = [];
        foreach ($rows as $index => $row) {
            $lastRow[$row['subject_type'].':'.$row['subject_id']] = $index;
        }
        foreach ($lastRow as $key => $index) {
            $rows[$index]['closing_flag'] = (bool) ($states[$key]['current_open'] ?? false);
            $rows[$index]['opening_flag'] = $openingState[$key] ?? false;
        }

        return [
            'rows' => $rows,
            'gaps' => $gaps,
            'unknowns' => $unknowns,
            'incident_count' => count($incidentSubjects),
            'violation_count' => count($violationSubjects),
            'action_due_count' => $actionDue,
            'action_overdue_count' => $actionOverdue,
            'action_closed_on_time_count' => $actionClosedOnTime,
            'opening_backlog_count' => count(array_filter($openingState)),
            'closing_backlog_count' => count(array_filter(
                $states,
                static fn (array $entityState): bool => (bool) $entityState['current_open'],
            )),
        ];
    }

    private function exposureCoverage(
        int $organizationId,
        array $projectIds,
        ReportQuery $query,
        CarbonImmutable $periodFrom,
        CarbonImmutable $periodTo,
        Collection $exposure,
        array $scopeResources,
    ): array {
        $sites = SafetySite::query()
            ->where('organization_id', $organizationId)
            ->where('created_at', '<=', $periodTo)
            ->whereDate('active_from', '<=', $periodTo->toDateString())
            ->where(static function (Builder $builder) use ($periodFrom): void {
                $builder->whereNull('active_until')
                    ->orWhereDate('active_until', '>=', $periodFrom->toDateString());
            })
            ->when($projectIds !== [], static fn (Builder $builder) => $builder->whereIn('project_id', $projectIds));
        $this->applyFilter($sites, 'project_id', $query->filters->values['project_id'] ?? null);
        $this->applyFilter($sites, 'id', $query->filters->values['safety_site_id'] ?? $query->filters->values['site_id'] ?? null);
        $this->applyExposureResourceScope($sites, $scopeResources, 'id');
        $siteIds = $sites->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $expected = count($siteIds) * ($periodFrom->startOfDay()->diffInDays($periodTo->startOfDay()) + 1);
        $actualKeys = $exposure
            ->map(static fn (SafetyExposureDay $day): string => $day->safety_site_id.':'.$day->exposure_date->toDateString())
            ->unique();
        $contractorScoped = $this->filterValues($query->filters->values['contractor_id'] ?? null) !== []
            || collect($scopeResources)->contains(
                static fn (mixed $resource): bool => $resource instanceof ReportScopedResource
                    && $resource->kind === 'contractor',
            );
        $subjectScoped = collect($scopeResources)->contains(
            static fn (mixed $resource): bool => $resource instanceof ReportScopedResource
                && in_array($resource->kind, [
                    'safety_incident',
                    'safety_violation',
                    'safety_corrective_action',
                ], true),
        );
        $complete = ! $contractorScoped
            && ! $subjectScoped
            && $expected > 0
            && $actualKeys->count() === $expected
            && $exposure->every(static fn (SafetyExposureDay $day): bool => (bool) $day->complete);
        $scaledHours = 0;
        foreach ($exposure as $day) {
            [$whole, $fraction] = array_pad(explode('.', (string) $day->exposure_hours, 2), 2, '');
            $scaledHours += ((int) $whole * 10_000) + (int) str_pad($fraction, 4, '0');
        }

        return [
            'complete' => $complete,
            'expected_days' => $expected,
            'projected_days' => $actualKeys->count(),
            'hours' => sprintf('%d.%04d', intdiv($scaledHours, 10_000), $scaledHours % 10_000),
        ];
    }

    private function ownerSubjectCount(
        int $organizationId,
        array $projectIds,
        array $resources,
        CarbonImmutable $asOf,
        ReportQuery $query,
    ): int {
        $subjectTypes = $this->filterValues($query->filters->values['subject_type'] ?? null);
        $count = 0;
        foreach ([
            'incident' => ['table' => 'safety_incidents', 'category' => 'incident_type'],
            'violation' => ['table' => 'safety_violations', 'category' => null],
            'corrective_action' => ['table' => 'safety_corrective_actions', 'category' => 'source_type'],
        ] as $subjectType => $source) {
            if ($subjectTypes !== [] && ! in_array($subjectType, $subjectTypes, true)) {
                continue;
            }
            $table = $source['table'];
            $builder = DB::table($table)
                ->where('organization_id', $organizationId)
                ->where('created_at', '<=', $asOf);
            if ($projectIds !== []) {
                $builder->whereIn('project_id', $projectIds);
            }
            if ($resources !== []) {
                $builder->where(function ($builder) use ($resources, $subjectType, $table, $asOf, $organizationId): void {
                    foreach ($resources as $resource) {
                        if (! $resource instanceof ReportScopedResource) {
                            continue;
                        }
                        $builder->orWhere(function ($builder) use ($resource, $subjectType, $table, $asOf, $organizationId): void {
                            match ($resource->kind) {
                                'project' => $builder->where($table.'.project_id', $resource->id),
                                'safety_incident' => $subjectType === 'incident'
                                    ? $builder->where($table.'.id', $resource->id)
                                    : $builder->whereRaw('1 = 0'),
                                'safety_violation' => $subjectType === 'violation'
                                    ? $builder->where($table.'.id', $resource->id)
                                    : $builder->whereRaw('1 = 0'),
                                'safety_corrective_action' => $subjectType === 'corrective_action'
                                    ? $builder->where($table.'.id', $resource->id)
                                    : $builder->whereRaw('1 = 0'),
                                'safety_site', 'contractor' => $builder->whereExists(function ($subquery) use ($resource, $subjectType, $table, $asOf, $organizationId): void {
                                    $subquery->selectRaw('1')->from('safety_transition_events as resource_event')
                                        ->whereColumn('resource_event.subject_id', $table.'.id')
                                        ->where('resource_event.organization_id', $organizationId)
                                        ->where('resource_event.subject_type', $subjectType)
                                        ->where('resource_event.occurred_at', '<=', $asOf)
                                        ->where(
                                            $resource->kind === 'safety_site'
                                                ? 'resource_event.safety_site_id'
                                                : 'resource_event.contractor_id',
                                            $resource->id,
                                        );
                                }),
                                default => $builder->whereRaw('1 = 0'),
                            };
                            if ($resource->projectId !== null) {
                                $builder->where($table.'.project_id', $resource->projectId);
                            }
                        });
                    }
                });
            }
            $siteIds = $this->filterValues(
                $query->filters->values['safety_site_id'] ?? $query->filters->values['site_id'] ?? null,
            );
            $contractorIds = $this->filterValues($query->filters->values['contractor_id'] ?? null);
            if ($siteIds !== [] || $contractorIds !== []) {
                $builder->whereExists(function ($subquery) use (
                    $table,
                    $subjectType,
                    $organizationId,
                    $asOf,
                    $siteIds,
                    $contractorIds,
                ): void {
                    $subquery->selectRaw('1')
                        ->from('safety_transition_events as scoped_event')
                        ->whereColumn('scoped_event.subject_id', $table.'.id')
                        ->where('scoped_event.organization_id', $organizationId)
                        ->where('scoped_event.subject_type', $subjectType)
                        ->where('scoped_event.occurred_at', '<=', $asOf);
                    if ($siteIds !== []) {
                        $subquery->whereIn('scoped_event.safety_site_id', $siteIds);
                    }
                    if ($contractorIds !== []) {
                        $subquery->whereIn('scoped_event.contractor_id', $contractorIds);
                    }
                });
            }
            foreach ([
                'severity' => 'severity',
                'owner_user_id' => 'assigned_to_user_id',
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
                    '(SELECT latest.to_status FROM safety_transition_events latest'
                    .' WHERE latest.organization_id = '.$table.'.organization_id'
                    .' AND latest.subject_type = ? AND latest.subject_id = '.$table.'.id'
                    .' AND latest.occurred_at <= ? ORDER BY latest.occurred_at DESC, latest.event_version DESC LIMIT 1)'
                    ." IN ($placeholders)",
                    [$subjectType, $asOf, ...$statuses],
                );
            }
            $categories = $this->filterValues($query->filters->values['category'] ?? null);
            if ($categories !== []) {
                $source['category'] === null
                    ? $builder->whereRaw('1 = 0')
                    : $builder->whereIn($source['category'], $categories);
            }
            if ($subjectType !== 'incident') {
                $dueFrom = $this->filterValues($query->filters->values['due_from'] ?? null);
                $dueTo = $this->filterValues($query->filters->values['due_to'] ?? null);
                if ($dueFrom !== []) {
                    $builder->whereDate('due_date', '>=', (string) $dueFrom[0]);
                }
                if ($dueTo !== []) {
                    $builder->whereDate('due_date', '<=', (string) $dueTo[0]);
                }
            } elseif (($query->filters->values['due_from'] ?? null) !== null
                || ($query->filters->values['due_to'] ?? null) !== null) {
                $builder->whereRaw('1 = 0');
            }
            $count += $builder->count();
        }

        return $count;
    }

    private function isConfiguredTerminal(
        SafetyTransitionEvent $event,
        SafetyIncidentPolicyVersion $policy,
    ): bool {
        $byType = $policy->terminal_statuses;
        $statuses = is_array($byType) ? ($byType[$event->subject_type] ?? []) : [];

        return is_array($statuses) && in_array((string) $event->to_status, $statuses, true);
    }

    private function timezone(?int $siteId, Collection $sites): ?string
    {
        $site = $siteId === null ? null : $sites->get($siteId);
        if (! $site instanceof SafetySite || trim((string) $site->timezone) === '') {
            return null;
        }

        try {
            return (new \DateTimeZone((string) $site->timezone))->getName();
        } catch (Throwable) {
            return null;
        }
    }

    private function applyFilter(Builder|QueryBuilder $builder, string $column, mixed $value): void
    {
        $values = $this->filterValues($value);
        if ($values !== []) {
            $builder->whereIn($column, $values);
        }
    }

    private function applyResourceScope(Builder|QueryBuilder $builder, array $resources): void
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
                    'safety_corrective_action',
                    'safety_incident',
                    'safety_site',
                    'safety_violation',
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
                        'safety_site' => $builder->where('safety_site_id', $resource->id),
                        'safety_incident' => $builder
                            ->where('subject_type', 'incident')
                            ->where('subject_id', $resource->id),
                        'safety_violation' => $builder
                            ->where('subject_type', 'violation')
                            ->where('subject_id', $resource->id),
                        'safety_corrective_action' => $builder
                            ->where('subject_type', 'corrective_action')
                            ->where('subject_id', $resource->id),
                    };
                    if ($resource->projectId !== null) {
                        $builder->where('project_id', $resource->projectId);
                    }
                });
            }
        });
    }

    private function applyExposureResourceScope(
        Builder $builder,
        array $resources,
        string $siteColumn = 'safety_site_id',
    ): void {
        if ($resources === []) {
            return;
        }

        $supported = array_values(array_filter(
            $resources,
            static fn (mixed $resource): bool => $resource instanceof ReportScopedResource
                && in_array($resource->kind, ['project', 'safety_site'], true),
        ));
        if ($supported === []) {
            return;
        }

        $builder->where(static function (Builder $builder) use ($siteColumn, $supported): void {
            foreach ($supported as $resource) {
                $builder->orWhere(static function (Builder $builder) use ($resource, $siteColumn): void {
                    if ($resource->kind === 'project') {
                        $builder->where('project_id', $resource->id);
                    } else {
                        $builder->where($siteColumn, $resource->id);
                    }
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

    private function assertPublicFilterValues(ReportQuery $query): void
    {
        $subjectTypes = $this->filterValues($query->filters->values['subject_type'] ?? null);
        $severities = $this->filterValues($query->filters->values['severity'] ?? null);
        $statuses = $this->filterValues($query->filters->values['status'] ?? null);
        if (array_diff($subjectTypes, ['incident', 'violation', 'corrective_action']) !== []
            || array_diff($severities, ['minor', 'major', 'high', 'critical']) !== []
            || array_diff($statuses, [
                'reported',
                'triage',
                'investigation',
                'corrective_actions',
                'open',
                'resolved',
                'verified',
                'closed',
                'cancelled',
            ]) !== []) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_VALUE_NOT_FOUND);
        }
    }
}
