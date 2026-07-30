<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Services;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Core\Reporting\Support\CompletedReportSourceLedgerBinding;
use App\BusinessModules\Core\Reporting\Support\ReportIdentitySetReconciler;
use App\BusinessModules\Core\Reporting\Support\ReportSnapshotFirstWriter;
use App\BusinessModules\Features\SafetyManagement\DTOs\SafetyComplianceContext;
use App\BusinessModules\Features\SafetyManagement\DTOs\SafetyComplianceRequirementResult;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\DTO\AdmissionRequirementState;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Models\SafetyAdmissionPolicyVersion;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Models\SafetyAdmissionRow;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Models\SafetyAdmissionSnapshot;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Models\SafetySiteWorkforceAssignment;
use App\BusinessModules\Features\SafetyManagement\Services\SafetyComplianceService;
use App\Jobs\ReportingSourceBackfillJob;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class WorkforceAdmissionSnapshotMaterializer
{
    private const MAPPING_SOURCE = 'workforce_employee_assignments';

    public function __construct(
        private SafetyComplianceService $compliance,
        private WorkforceAdmissionFormula $formula,
        private SafetyEvidenceVersionResolver $evidenceVersions,
    ) {}

    public function materialize(ReportExecutionContext $context, ReportQuery $query): SafetyAdmissionSnapshot
    {
        $organizationId = $context->scope->organizationId;
        ReportingSourceBackfillJob::request($organizationId, ReportingSourceBackfillJob::WORKFORCE_ADMISSION);
        $ledgerBinding = CompletedReportSourceLedgerBinding::capture(
            $organizationId,
            [ReportingSourceBackfillJob::WORKFORCE_ADMISSION],
        );

        return ReportSnapshotFirstWriter::run(
            'workforce_admission:'.$organizationId.':'.$query->definition->definitionHash->value
            .':'.$query->queryHash->value.':'.$query->asOf->format(DATE_ATOM),
            fn (): SafetyAdmissionSnapshot => $this->materializeLocked($context, $query, $ledgerBinding),
        );
    }

    private function materializeLocked(
        ReportExecutionContext $context,
        ReportQuery $query,
        array $ledgerBinding,
    ): SafetyAdmissionSnapshot {
        $organizationId = $context->scope->organizationId;
        CompletedReportSourceLedgerBinding::lockAndAssertOwnerGeneration($organizationId, $ledgerBinding);
        $asOf = CarbonImmutable::instance($query->asOf);
        $date = $asOf->startOfDay();
        $assignments = $this->assignments(
            $organizationId,
            $context->scope->projectIds,
            $context->scope->resources,
            $date,
            $asOf,
            $query,
        );
        $projection = $this->projection($organizationId, $assignments, $date, $asOf, $query);
        $ownerSources = $this->ownerSources(
            $organizationId,
            $context->scope->projectIds,
            $context->scope->resources,
            $date,
            $asOf,
            $query,
        );
        $ownerKeys = $ownerSources->map(
            static fn (object $row): string => $row->mapping_id === null
                ? 'assignment:'.$row->assignment_id.':employee:'.$row->employee_id.':site:missing'
                : 'assignment:'.$row->assignment_id.':employee:'.$row->employee_id.':site:'.$row->safety_site_id,
        );
        $projectedKeys = $assignments->map(
            static fn (SafetySiteWorkforceAssignment $mapping): string => 'assignment:'.$mapping->workforce_assignment_id
                .':employee:'.$mapping->employee_id.':site:'.$mapping->safety_site_id,
        );
        $mappingGapCount = ReportIdentitySetReconciler::gapCount($ownerKeys, $projectedKeys);
        $projection['gap_count'] += $mappingGapCount;
        $projection['eligible_count'] += $mappingGapCount;
        $policyIds = collect($projection['policies'])
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $inputHash = hash('sha256', CanonicalJson::encode([
            'assignments' => $assignments->pluck('source_hash')->all(),
            'date' => $date->toDateString(),
            'evidence_hashes' => $projection['evidence_hashes'],
            'filters' => $query->filters->values,
            'policies' => collect($projection['policies'])
                ->map(static fn (SafetyAdmissionPolicyVersion $policy): array => [
                    'id' => (int) $policy->id,
                    'source_hash' => (string) $policy->source_hash,
                ])
                ->sortBy('id')
                ->values()
                ->all(),
            'workforce_assignment_hashes' => $projection['workforce_assignment_hashes'],
            'source_ledger_binding' => $ledgerBinding,
        ]));
        $outputHash = hash('sha256', CanonicalJson::encode([
            'rows' => $projection['rows'],
            'metrics' => array_map(static fn ($metric): array => [
                'assignment_id' => $metric->assignmentId,
                'person_id' => $metric->personId,
                'site_id' => $metric->siteId,
                'status' => $metric->status,
                'blocker_codes' => $metric->blockerCodes,
                'warning_codes' => $metric->warningCodes,
            ], $projection['metrics']),
            'coverage' => [
                'eligible' => $projection['eligible_count'],
                'projected' => count($projection['rows']),
                'gaps' => $projection['gap_count'],
                'unknowns' => $projection['unknown_count'],
            ],
        ]));
        $sourceHash = hash('sha256', CanonicalJson::encode([
            'input_hash' => $inputHash,
            'output_hash' => $outputHash,
            'query_hash' => $query->queryHash->value,
        ]));
        $scopeHash = hash('sha256', CanonicalJson::encode($context->scope->canonicalIdentity()));
        $existing = SafetyAdmissionSnapshot::query()
            ->where('organization_id', $organizationId)
            ->where('scope_hash', $scopeHash)
            ->where('snapshot_date', $date->toDateString())
            ->where('formula_version', $query->definition->formulaVersion)
            ->where('source_hash', $sourceHash)
            ->first();
        if ($existing instanceof SafetyAdmissionSnapshot) {
            return $existing;
        }

        return ReportSnapshotFirstWriter::run(
            'workforce_admission:'.$organizationId.':'.$scopeHash.':'.$sourceHash,
            function () use (
                $query,
                $organizationId,
                $date,
                $projection,
                $policyIds,
                $inputHash,
                $ledgerBinding,
                $outputHash,
                $sourceHash,
                $scopeHash,
            ): SafetyAdmissionSnapshot {
                $existing = SafetyAdmissionSnapshot::query()
                    ->where('organization_id', $organizationId)
                    ->where('scope_hash', $scopeHash)
                    ->where('snapshot_date', $date->toDateString())
                    ->where('formula_version', $query->definition->formulaVersion)
                    ->where('source_hash', $sourceHash)
                    ->first();
                if ($existing instanceof SafetyAdmissionSnapshot) {
                    return $existing;
                }

                return DB::transaction(function () use (
                    $query,
                    $organizationId,
                    $date,
                    $projection,
                    $policyIds,
                    $inputHash,
                    $ledgerBinding,
                    $outputHash,
                    $sourceHash,
                    $scopeHash,
                ): SafetyAdmissionSnapshot {
                    $rows = $projection['rows'];
                    $summary = $this->formula->summarize($projection['metrics']);
                    $generatedAt = CarbonImmutable::now();
                    $siteFilter = $this->filterValues($query->filters->values['safety_site_id'] ?? $query->filters->values['site_id'] ?? null);
                    $snapshot = SafetyAdmissionSnapshot::query()->create([
                        'id' => (string) Str::ulid(),
                        'organization_id' => $organizationId,
                        'project_id' => count($query->scope->projectIds) === 1 ? $query->scope->projectIds[0] : null,
                        'safety_site_id' => count($siteFilter) === 1 ? (int) $siteFilter[0] : null,
                        'policy_version_ids' => $policyIds,
                        'scope_hash' => $scopeHash,
                        'definition_hash' => $query->definition->definitionHash->value,
                        'formula_version' => $query->definition->formulaVersion,
                        'query_hash' => $query->queryHash->value,
                        'input_hash' => $inputHash,
                        'output_hash' => $outputHash,
                        'source_hash' => $sourceHash,
                        'snapshot_date' => $date->toDateString(),
                        'source_watermark' => CarbonImmutable::parse((string) $ledgerBinding['watermark']),
                        'source_ledger_binding' => $ledgerBinding,
                        'row_count' => count($rows),
                        'evaluated_people' => $summary->personDenominator,
                        'admitted_people' => $summary->admittedPeople,
                        'partial_people' => $summary->partialPeople,
                        'not_admitted_people' => $summary->notAdmittedPeople,
                        'blocker_count' => $projection['blocker_count'],
                        'expiring_count' => $projection['expiring_count'],
                        'unverified_count' => $projection['unknown_count'],
                        'eligible_count' => $projection['eligible_count'],
                        'projected_count' => count($rows),
                        'gap_count' => $projection['gap_count'],
                        'unknown_count' => $projection['unknown_count'],
                        'generated_at' => $generatedAt,
                        'stale_at' => $generatedAt->addMinutes(15),
                    ]);
                    foreach ($rows as $row) {
                        SafetyAdmissionRow::query()->create([
                            'organization_id' => $organizationId,
                            'snapshot_id' => $snapshot->id,
                        ] + $row);
                    }

                    return $snapshot;
                });
            },
        );
    }

    private function assignments(
        int $organizationId,
        array $scopeProjectIds,
        array $scopeResources,
        CarbonImmutable $date,
        CarbonImmutable $asOf,
        ReportQuery $query,
    ): Collection {
        $builder = SafetySiteWorkforceAssignment::query()
            ->where('organization_id', $organizationId)
            ->where('mapping_source', self::MAPPING_SOURCE)
            ->where('created_at', '<=', $asOf)
            ->whereDate('valid_from', '<=', $date->toDateString())
            ->where(static function (Builder $builder) use ($date): void {
                $builder->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date->toDateString());
            })
            ->when($scopeProjectIds !== [], static fn (Builder $builder) => $builder->whereIn('project_id', $scopeProjectIds));
        $this->applyFilter($builder, 'project_id', $query->filters->values['project_id'] ?? null);
        $this->applyFilter($builder, 'safety_site_id', $query->filters->values['safety_site_id'] ?? $query->filters->values['site_id'] ?? null);
        $this->applyFilter($builder, 'employee_id', $query->filters->values['employee_id'] ?? null);
        $this->applyFilter($builder, 'workforce_assignment_id', $query->filters->values['workforce_assignment_id'] ?? null);
        $this->applyResourceScope($builder, $scopeResources);

        return $builder
            ->orderBy('project_id')
            ->orderBy('safety_site_id')
            ->orderBy('employee_id')
            ->orderBy('id')
            ->get();
    }

    private function ownerSources(
        int $organizationId,
        array $scopeProjectIds,
        array $scopeResources,
        CarbonImmutable $date,
        CarbonImmutable $asOf,
        ReportQuery $query,
    ): Collection {
        $builder = DB::table('workforce_employee_assignments as owner')
            ->leftJoin('safety_site_workforce_assignments as mapping', function ($join) use ($date, $asOf): void {
                $join->on('mapping.organization_id', '=', 'owner.organization_id')
                    ->on('mapping.workforce_assignment_id', '=', 'owner.id')
                    ->where('mapping.created_at', '<=', $asOf)
                    ->whereDate('mapping.valid_from', '<=', $date->toDateString())
                    ->where(static function ($query) use ($date): void {
                        $query->whereNull('mapping.valid_to')->orWhereDate('mapping.valid_to', '>=', $date->toDateString());
                    });
            })
            ->where('owner.organization_id', $organizationId)
            ->whereNotNull('owner.project_id')
            ->where('owner.created_at', '<=', $asOf)
            ->whereDate('owner.valid_from', '<=', $date->toDateString())
            ->where(static function ($builder) use ($date): void {
                $builder->whereNull('owner.valid_to')->orWhereDate('owner.valid_to', '>=', $date->toDateString());
            })
            ->where(static function ($builder) use ($asOf): void {
                $builder->whereNull('owner.deleted_at')->orWhere('owner.deleted_at', '>', $asOf);
            });
        if ($scopeProjectIds !== []) {
            $builder->whereIn('owner.project_id', $scopeProjectIds);
        }
        foreach ([
            'owner.project_id' => $query->filters->values['project_id'] ?? null,
            'owner.employee_id' => $query->filters->values['employee_id'] ?? null,
            'owner.id' => $query->filters->values['workforce_assignment_id'] ?? null,
            'mapping.safety_site_id' => $query->filters->values['safety_site_id'] ?? $query->filters->values['site_id'] ?? null,
        ] as $column => $filter) {
            $values = $this->filterValues($filter);
            if ($values !== []) {
                $builder->whereIn($column, $values);
            }
        }
        if ($scopeResources !== []) {
            $builder->where(function ($builder) use ($scopeResources): void {
                foreach ($scopeResources as $resource) {
                    if (! $resource instanceof ReportScopedResource) {
                        continue;
                    }
                    $builder->orWhere(function ($builder) use ($resource): void {
                        match ($resource->kind) {
                            'project' => $builder->where('owner.project_id', $resource->id),
                            'safety_site' => $builder->where('mapping.safety_site_id', $resource->id),
                            'workforce_assignment' => $builder->where('owner.id', $resource->id),
                            'workforce_employee' => $builder->where('owner.employee_id', $resource->id),
                            default => $builder->whereRaw('1 = 0'),
                        };
                        if ($resource->projectId !== null) {
                            $builder->where('owner.project_id', $resource->projectId);
                        }
                    });
                }
            });
        }

        return $builder->get([
            'owner.id as assignment_id',
            'owner.employee_id',
            'mapping.id as mapping_id',
            'mapping.safety_site_id',
        ]);
    }

    private function projection(
        int $organizationId,
        Collection $assignments,
        CarbonImmutable $date,
        CarbonImmutable $asOf,
        ReportQuery $query,
    ): array {
        $rows = [];
        $metrics = [];
        $policies = [];
        $evidenceHashes = [];
        $workforceAssignmentHashes = [];
        $unknownCount = 0;
        $blockerCount = 0;
        $expiringCount = 0;
        $gapCount = 0;
        $resolvedEvidenceVersions = [];

        if ($assignments->isEmpty()) {
            $projectIds = $query->scope->projectIds;
            $siteIds = $this->filterValues($query->filters->values['safety_site_id'] ?? $query->filters->values['site_id'] ?? null);
            if (count($projectIds) !== 1 || count($siteIds) !== 1) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
            }
            $policy = $this->policy($organizationId, (int) $projectIds[0], (int) $siteIds[0], $date, $asOf);
            $policies[(int) $policy->id] = $policy;
        }

        foreach ($assignments as $assignment) {
            $policy = $this->policy(
                $organizationId,
                (int) $assignment->project_id,
                (int) $assignment->safety_site_id,
                $date,
                $asOf,
            );
            $policies[(int) $policy->id] = $policy;
            $requirements = $policy->mandatory_requirements;
            if (! is_array($requirements) || $requirements === []) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
            }
            $workforceAssignment = $this->workforceAssignmentState($assignment, $date, $asOf);
            $workforceAssignmentHashes[] = $workforceAssignment['source_hash'];
            if (! $workforceAssignment['active']) {
                $gapCount++;

                continue;
            }

            $complianceContext = new SafetyComplianceContext(
                organizationId: $organizationId,
                employeeId: (int) $assignment->employee_id,
                projectId: (int) $assignment->project_id,
                date: $date,
                siteId: (int) $assignment->safety_site_id,
                evidenceCutoff: $asOf,
            );
            try {
                $results = $this->compliance->checkPinnedRequirements($complianceContext, $requirements);
                $lifecycleFlags = $this->compliance->pinnedLifecycleFlags($complianceContext);
            } catch (DomainException $exception) {
                $gapCount++;

                continue;
            }
            $states = [
                ...$this->requirementStates($policy, $results),
                ...$this->lifecycleStates($lifecycleFlags),
            ];
            $versionedStates = [];
            foreach ($states as $state) {
                $evidenceVersion = null;
                if ($state->evidenceType !== null && $state->evidenceId !== null) {
                    $evidenceVersion = $this->evidenceVersions->effective(
                        $organizationId,
                        $state->evidenceType,
                        $state->evidenceId,
                        $asOf,
                    );
                    if ($evidenceVersion === null) {
                        $gapCount++;
                        $unknownCount++;

                        continue;
                    }
                    $resolvedEvidenceVersions[$state->evidenceType.':'.$state->evidenceId] = $evidenceVersion;
                }
                $evidenceHashes[] = hash('sha256', CanonicalJson::encode([
                    'assignment_id' => (int) $assignment->workforce_assignment_id,
                    'code' => $state->code,
                    'evidence_id' => $state->evidenceId,
                    'evidence_type' => $state->evidenceType,
                    'evidence_version_hash' => $evidenceVersion['hash'] ?? null,
                    'evidence_version_id' => $evidenceVersion['id'] ?? null,
                    'status' => $state->status,
                    'valid_until' => $state->validUntil?->format('Y-m-d'),
                ]));
                $versionedStates[] = $state;
            }
            $states = $versionedStates;
            $includedStates = array_values(array_filter(
                $states,
                fn (AdmissionRequirementState $state): bool => $this->stateMatchesFilters($state, $query),
            ));
            if ($includedStates === []) {
                continue;
            }
            $metric = $this->formula->evaluate(
                (int) $assignment->workforce_assignment_id,
                (int) $assignment->employee_id,
                (int) $assignment->safety_site_id,
                $date->toDateString(),
                $includedStates,
            );
            $blockedFilter = $this->filterValues($query->filters->values['blocked'] ?? null);
            if ($blockedFilter !== []) {
                $includedStates = array_values(array_filter(
                    $includedStates,
                    static fn (AdmissionRequirementState $state): bool => in_array(
                        in_array($state->code, $metric->blockerCodes, true),
                        $blockedFilter,
                        true,
                    ),
                ));
                if ($includedStates === []) {
                    continue;
                }
                $metric = $this->formula->evaluate(
                    (int) $assignment->workforce_assignment_id,
                    (int) $assignment->employee_id,
                    (int) $assignment->safety_site_id,
                    $date->toDateString(),
                    $includedStates,
                );
            }
            foreach ($includedStates as $state) {
                $blocked = in_array($state->code, $metric->blockerCodes, true);
                $evidenceVersion = $state->evidenceType === null || $state->evidenceId === null
                    ? null
                    : ($resolvedEvidenceVersions[$state->evidenceType.':'.$state->evidenceId] ?? null);
                if ($state->evidenceId !== null && $evidenceVersion === null) {
                    continue;
                }
                $rows[] = [
                    'project_id' => (int) $assignment->project_id,
                    'safety_site_id' => (int) $assignment->safety_site_id,
                    'site_assignment_id' => (int) $assignment->id,
                    'workforce_assignment_id' => (int) $assignment->workforce_assignment_id,
                    'employee_id' => (int) $assignment->employee_id,
                    'snapshot_date' => $date->toDateString(),
                    'row_type' => 'requirement',
                    'row_key' => sprintf(
                        'assignment:%d:site:%d:employee:%d:requirement:%s',
                        $assignment->workforce_assignment_id,
                        $assignment->safety_site_id,
                        $assignment->employee_id,
                        $state->code,
                    ),
                    'requirement_code' => $state->code,
                    'requirement_type' => $state->type,
                    'status' => $state->status,
                    'mandatory' => $state->mandatory,
                    'blocked' => $blocked,
                    'verified' => $state->verified,
                    'valid_until' => $state->validUntil?->format('Y-m-d'),
                    'evidence_type' => $state->evidenceType,
                    'evidence_id' => $state->evidenceId,
                    'evidence_version_id' => $evidenceVersion['id'] ?? null,
                    'evidence_hash' => $evidenceVersion['hash'] ?? null,
                    'evidence_identity' => $evidenceVersion === null ? null : [
                        'evidence_id' => $state->evidenceId,
                        'evidence_type' => $state->evidenceType,
                        'version_hash' => $evidenceVersion['hash'],
                        'version_id' => $evidenceVersion['id'],
                    ],
                    'medical_details' => $state->type === 'medical_exam' ? [
                        'source_type' => 'medical_exam',
                        'source_id' => $state->evidenceId,
                    ] : null,
                    'blocker_codes' => $blocked ? [$state->code] : [],
                ];
            }
            $metrics[] = $metric;
            $includedCodes = array_map(
                static fn (AdmissionRequirementState $state): string => $state->code,
                $includedStates,
            );
            $blockerCount += count(array_intersect($metric->blockerCodes, $includedCodes));
            $unknownCount += count(array_filter(
                $includedStates,
                static fn (AdmissionRequirementState $state): bool => ! $state->verified,
            ));
            $expiringCount += count(array_filter(
                $includedStates,
                static fn (AdmissionRequirementState $state): bool => $state->validUntil !== null
                    && $state->validUntil >= $date
                    && $state->validUntil <= $date->addDays((int) $policy->expiring_soon_days),
            ));
        }

        sort($evidenceHashes, SORT_STRING);
        sort($workforceAssignmentHashes, SORT_STRING);

        return [
            'rows' => $rows,
            'metrics' => $metrics,
            'policies' => $policies,
            'evidence_hashes' => $evidenceHashes,
            'workforce_assignment_hashes' => $workforceAssignmentHashes,
            'eligible_count' => count($rows) + $gapCount,
            'gap_count' => $gapCount,
            'unknown_count' => $unknownCount,
            'blocker_count' => $blockerCount,
            'expiring_count' => $expiringCount,
        ];
    }

    private function workforceAssignmentState(
        SafetySiteWorkforceAssignment $mapping,
        CarbonImmutable $date,
        CarbonImmutable $asOf,
    ): array {
        $lifecycle = DB::table('safety_workforce_lifecycle_events')
            ->where('organization_id', $mapping->organization_id)
            ->where('subject_type', 'assignment')
            ->where('subject_id', $mapping->workforce_assignment_id)
            ->where('occurred_at', '<=', $asOf)
            ->orderByDesc('occurred_at')
            ->orderByDesc('event_version')
            ->first();
        $record = DB::table('workforce_employee_assignments')
            ->where('id', $mapping->workforce_assignment_id)
            ->where('organization_id', $mapping->organization_id)
            ->where('project_id', $mapping->project_id)
            ->where('employee_id', $mapping->employee_id)
            ->first([
                'id',
                'organization_id',
                'project_id',
                'employee_id',
                'status',
                'valid_from',
                'valid_to',
                'created_at',
                'updated_at',
                'deleted_at',
            ]);
        $source = $record === null ? [
            'mapping_id' => (int) $mapping->id,
            'state' => 'missing',
        ] : [
            'created_at' => (string) $record->created_at,
            'deleted_at' => $record->deleted_at === null ? null : (string) $record->deleted_at,
            'employee_id' => (int) $record->employee_id,
            'id' => (int) $record->id,
            'organization_id' => (int) $record->organization_id,
            'project_id' => (int) $record->project_id,
            'status' => (string) $record->status,
            'updated_at' => (string) $record->updated_at,
            'valid_from' => (string) $record->valid_from,
            'valid_to' => $record->valid_to === null ? null : (string) $record->valid_to,
        ];
        $active = false;
        if ($record !== null && $lifecycle !== null && (bool) $lifecycle->history_complete) {
            try {
                $active = $lifecycle->status === 'active'
                    && CarbonImmutable::parse((string) $record->created_at) <= $asOf
                    && ($record->deleted_at === null || CarbonImmutable::parse((string) $record->deleted_at) > $asOf)
                    && CarbonImmutable::parse((string) $lifecycle->valid_from)->toDateString() <= $date->toDateString()
                    && (
                        $lifecycle->valid_to === null
                        || CarbonImmutable::parse((string) $lifecycle->valid_to)->toDateString() >= $date->toDateString()
                    );
            } catch (Throwable) {
                $active = false;
            }
        }

        return [
            'active' => $active,
            'source_hash' => hash('sha256', CanonicalJson::encode([
                'record' => $source,
                'lifecycle_hash' => $lifecycle?->source_hash,
                'lifecycle_history_complete' => $lifecycle === null ? false : (bool) $lifecycle->history_complete,
            ])),
        ];
    }

    private function policy(
        int $organizationId,
        int $projectId,
        int $siteId,
        CarbonImmutable $date,
        CarbonImmutable $asOf,
    ): SafetyAdmissionPolicyVersion {
        $policy = SafetyAdmissionPolicyVersion::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('created_at', '<=', $asOf)
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(static function (Builder $builder) use ($date): void {
                $builder->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date->toDateString());
            })
            ->where(static function (Builder $builder) use ($siteId): void {
                $builder->whereNull('safety_site_id')->orWhere('safety_site_id', $siteId);
            })
            ->orderByRaw('safety_site_id IS NULL')
            ->first();
        if (! $policy instanceof SafetyAdmissionPolicyVersion) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }
        $requirements = $policy->mandatory_requirements;
        if (! is_array($requirements) || $requirements === [] || ! array_is_list($requirements)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }
        $seen = [];
        foreach ($requirements as $requirement) {
            $code = is_array($requirement) ? ($requirement['code'] ?? null) : null;
            $type = is_array($requirement) ? ($requirement['type'] ?? null) : null;
            if (! is_string($code)
                || trim($code) === ''
                || ! is_string($type)
                || trim($type) === ''
                || ! array_key_exists('mandatory', $requirement)
                || ! is_bool($requirement['mandatory'])
                || (array_key_exists('waiver_allowed', $requirement) && ! is_bool($requirement['waiver_allowed']))
                || isset($seen[$code])) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
            }
            $seen[$code] = true;
        }

        return $policy;
    }

    private function requirementStates(SafetyAdmissionPolicyVersion $policy, array $results): array
    {
        $byCode = [];
        foreach ($results as $result) {
            if ($result instanceof SafetyComplianceRequirementResult) {
                $byCode[$result->code] = $result;
            }
        }

        $states = [];
        foreach ($policy->mandatory_requirements as $requirement) {
            if (! is_array($requirement)
                || ! is_string($requirement['code'] ?? null)
                || ! is_string($requirement['type'] ?? null)
                || ! array_key_exists('mandatory', $requirement)) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
            }
            $result = $byCode[$requirement['code']] ?? null;
            $states[] = new AdmissionRequirementState(
                code: $requirement['code'],
                type: $requirement['type'],
                status: $result?->status ?? 'missing',
                mandatory: (bool) $requirement['mandatory'],
                verified: $result?->sourceId !== null,
                validUntil: $result?->validUntil?->toDateTimeImmutable(),
                evidenceType: $result?->sourceType,
                evidenceId: $result?->sourceId,
                medicalDetails: null,
                waiverAllowed: (bool) ($requirement['waiver_allowed'] ?? false),
                waiverEvidenceRequired: (bool) $policy->waiver_evidence_required,
            );
        }

        return $states;
    }

    private function lifecycleStates(array $flags): array
    {
        if ($flags === []) {
            return [new AdmissionRequirementState(
                code: 'employment_lifecycle',
                type: 'employment_lifecycle',
                status: 'fulfilled',
                mandatory: true,
                verified: true,
                validUntil: null,
                evidenceType: 'workforce_employee',
                evidenceId: null,
            )];
        }

        return array_map(static function (array $flag): AdmissionRequirementState {
            $code = $flag['code'] ?? null;
            if (! is_string($code) || trim($code) === '') {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
            }

            return new AdmissionRequirementState(
                code: 'employment_lifecycle:'.$code,
                type: 'employment_lifecycle',
                status: 'failed',
                mandatory: true,
                verified: true,
                validUntil: null,
                evidenceType: 'workforce_employee',
                evidenceId: null,
            );
        }, $flags);
    }

    private function stateMatchesFilters(AdmissionRequirementState $state, ReportQuery $query): bool
    {
        foreach ([
            'requirement_code' => $state->code,
            'requirement_type' => $state->type,
            'status' => $state->status,
            'mandatory' => $state->mandatory,
            'verified' => $state->verified,
        ] as $filter => $value) {
            $values = $this->filterValues($query->filters->values[$filter] ?? null);
            if ($values !== [] && ! in_array($value, $values, true)) {
                return false;
            }
        }

        return true;
    }

    private function applyFilter(Builder $builder, string $column, mixed $value): void
    {
        $values = $this->filterValues($value);
        if ($values !== []) {
            $builder->whereIn($column, $values);
        }
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
                    'project',
                    'safety_site',
                    'workforce_assignment',
                    'workforce_employee',
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
                        'project' => $builder->where('project_id', $resource->id),
                        'safety_site' => $builder->where('safety_site_id', $resource->id),
                        'workforce_assignment' => $builder->where('workforce_assignment_id', $resource->id),
                        'workforce_employee' => $builder->where('employee_id', $resource->id),
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
            static fn (mixed $item): bool => is_bool($item) || is_int($item) || is_string($item),
        ));
    }
}
