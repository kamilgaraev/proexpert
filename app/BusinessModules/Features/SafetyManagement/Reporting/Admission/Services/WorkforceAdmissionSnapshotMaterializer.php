<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Services;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\SafetyManagement\DTOs\SafetyComplianceContext;
use App\BusinessModules\Features\SafetyManagement\DTOs\SafetyComplianceRequirementResult;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\DTO\AdmissionRequirementState;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Models\SafetyAdmissionPolicyVersion;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Models\SafetyAdmissionRow;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Models\SafetyAdmissionSnapshot;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Models\SafetySiteWorkforceAssignment;
use App\BusinessModules\Features\SafetyManagement\Services\SafetyComplianceService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class WorkforceAdmissionSnapshotMaterializer
{
    public function __construct(
        private SafetyComplianceService $compliance,
        private WorkforceAdmissionFormula $formula,
    ) {}

    public function materialize(ReportExecutionContext $context, ReportQuery $query): SafetyAdmissionSnapshot
    {
        $organizationId = $context->scope->organizationId;
        $date = CarbonImmutable::instance($query->asOf)->startOfDay();
        $assignments = SafetySiteWorkforceAssignment::query()
            ->where('organization_id', $organizationId)
            ->whereDate('valid_from', '<=', $date->toDateString())
            ->where(static function ($builder) use ($date): void {
                $builder->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date->toDateString());
            })
            ->when($context->scope->projectIds !== [], static fn ($builder) => $builder->whereIn('project_id', $context->scope->projectIds))
            ->orderBy('project_id')
            ->orderBy('safety_site_id')
            ->orderBy('employee_id')
            ->get();
        $projection = $this->projection($organizationId, $assignments->all(), $date, $query);
        if (count($projection['policies']) !== 1) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }
        $sourceHash = hash('sha256', CanonicalJson::encode([
            'assignments' => $assignments->pluck('source_hash')->all(),
            'date' => $date->toDateString(),
            'evidence' => $projection['evidence'],
            'policies' => array_map(
                static fn (SafetyAdmissionPolicyVersion $policy): array => [
                    'id' => (int) $policy->id,
                    'source_hash' => (string) $policy->source_hash,
                ],
                array_values($projection['policies']),
            ),
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

        return DB::transaction(function () use (
            $query,
            $organizationId,
            $date,
            $projection,
            $sourceHash,
            $scopeHash,
        ): SafetyAdmissionSnapshot {
            $rows = $projection['rows'];
            $summary = $this->formula->summarize($projection['metrics']);
            $policy = array_values($projection['policies'])[0];
            $generatedAt = CarbonImmutable::now();
            $snapshot = SafetyAdmissionSnapshot::query()->create([
                'id' => (string) Str::ulid(),
                'organization_id' => $organizationId,
                'project_id' => count($query->scope->projectIds) === 1 ? $query->scope->projectIds[0] : null,
                'safety_site_id' => null,
                'policy_version_id' => $policy->id,
                'scope_hash' => $scopeHash,
                'definition_hash' => $query->definition->definitionHash->value,
                'formula_version' => $query->definition->formulaVersion,
                'source_hash' => $sourceHash,
                'snapshot_date' => $date->toDateString(),
                'source_watermark' => $generatedAt,
                'row_count' => count($rows),
                'evaluated_people' => $summary->personDenominator,
                'admitted_people' => $summary->admittedPeople,
                'partial_people' => $summary->partialPeople,
                'not_admitted_people' => $summary->notAdmittedPeople,
                'blocker_count' => $projection['blocker_count'],
                'expiring_count' => $projection['expiring_count'],
                'unverified_count' => $projection['unknown_count'],
                'eligible_count' => count($rows),
                'projected_count' => count($rows),
                'gap_count' => 0,
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
    }

    private function projection(int $organizationId, array $assignments, CarbonImmutable $date, ReportQuery $query): array
    {
        $rows = [];
        $metrics = [];
        $policies = [];
        $evidence = [];
        $unknownCount = 0;
        $blockerCount = 0;
        $expiringCount = 0;

        if ($assignments === []) {
            if (count($query->scope->projectIds) !== 1) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
            }
            $policy = $this->policy($organizationId, $query->scope->projectIds[0], 0, $date);
            $policies[(int) $policy->id] = $policy;
        }

        foreach ($assignments as $assignment) {
            if (! $assignment instanceof SafetySiteWorkforceAssignment) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
            }
            $policy = $this->policy($organizationId, (int) $assignment->project_id, (int) $assignment->safety_site_id, $date);
            $policies[(int) $policy->id] = $policy;
            $complianceContext = new SafetyComplianceContext(
                organizationId: $organizationId,
                employeeId: (int) $assignment->employee_id,
                projectId: (int) $assignment->project_id,
                date: $date,
                siteId: (int) $assignment->safety_site_id,
            );
            $requirements = $policy->mandatory_requirements ?? null;
            if (! is_array($requirements)) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
            }
            try {
                $results = $this->compliance->checkPinnedRequirements($complianceContext, $requirements);
            } catch (DomainException $exception) {
                throw ReportContractException::fromCode(
                    ReportErrorCode::REPORT_SOURCE_UNAVAILABLE,
                    previous: $exception,
                );
            }
            $states = $this->requirementStates(
                $policy,
                $results,
            );
            $metric = $this->formula->evaluate(
                (int) $assignment->workforce_assignment_id,
                (int) $assignment->employee_id,
                (int) $assignment->safety_site_id,
                $date->toDateString(),
                $states,
            );
            $metrics[] = $metric;
            $blockerCount += count($metric->blockerCodes);
            $assignmentUnknowns = count(array_filter(
                $states,
                static fn (AdmissionRequirementState $state): bool => ! $state->verified,
            ));
            $unknownCount += $assignmentUnknowns;
            $evidence[] = [
                'assignment_id' => (int) $assignment->workforce_assignment_id,
                'employee_id' => (int) $assignment->employee_id,
                'site_id' => (int) $assignment->safety_site_id,
                'requirements' => array_map(
                    static fn (AdmissionRequirementState $state): array => [
                        'code' => $state->code,
                        'type' => $state->type,
                        'status' => $state->status,
                        'mandatory' => $state->mandatory,
                        'verified' => $state->verified,
                        'valid_until' => $state->validUntil?->format('Y-m-d'),
                        'evidence_type' => $state->evidenceType,
                        'evidence_id' => $state->evidenceId,
                    ],
                    $states,
                ),
            ];
            foreach ($states as $state) {
                $expiringCount += $state->validUntil !== null
                    && $state->validUntil >= $date
                    && $state->validUntil <= $date->addDays((int) $policy->expiring_soon_days)
                    ? 1
                    : 0;
                $blocked = in_array($state->code, $metric->blockerCodes, true);
                $rows[] = [
                    'project_id' => (int) $assignment->project_id,
                    'safety_site_id' => (int) $assignment->safety_site_id,
                    'workforce_assignment_id' => (int) $assignment->workforce_assignment_id,
                    'employee_id' => (int) $assignment->employee_id,
                    'snapshot_date' => $date->toDateString(),
                    'row_type' => 'requirement',
                    'row_key' => sprintf(
                        'assignment:%d:employee:%d:requirement:%s',
                        $assignment->workforce_assignment_id,
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
                    'medical_details' => $state->medicalDetails,
                    'blocker_codes' => $blocked ? [$state->code] : [],
                ];
            }
            $rows[] = [
                'project_id' => (int) $assignment->project_id,
                'safety_site_id' => (int) $assignment->safety_site_id,
                'workforce_assignment_id' => (int) $assignment->workforce_assignment_id,
                'employee_id' => (int) $assignment->employee_id,
                'snapshot_date' => $date->toDateString(),
                'row_type' => 'person_summary',
                'row_key' => sprintf('assignment:%d:employee:%d:summary', $assignment->workforce_assignment_id, $assignment->employee_id),
                'requirement_code' => 'person_summary',
                'requirement_type' => 'summary',
                'status' => $metric->status,
                'mandatory' => true,
                'blocked' => $metric->blocked,
                'verified' => $assignmentUnknowns === 0,
                'valid_until' => null,
                'evidence_type' => null,
                'evidence_id' => null,
                'medical_details' => null,
                'blocker_codes' => $metric->blockerCodes,
            ];
        }

        return [
            'rows' => $rows,
            'metrics' => $metrics,
            'policies' => $policies,
            'evidence' => $evidence,
            'unknown_count' => $unknownCount,
            'blocker_count' => $blockerCount,
            'expiring_count' => $expiringCount,
        ];
    }

    private function policy(int $organizationId, int $projectId, int $siteId, CarbonImmutable $date): SafetyAdmissionPolicyVersion
    {
        $policy = SafetyAdmissionPolicyVersion::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(static function ($builder) use ($date): void {
                $builder->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date->toDateString());
            })
            ->where(static function ($builder) use ($siteId): void {
                $builder->whereNull('safety_site_id')->orWhere('safety_site_id', $siteId);
            })
            ->orderByRaw('safety_site_id IS NULL')
            ->orderByDesc('effective_from')
            ->first();
        if (! $policy instanceof SafetyAdmissionPolicyVersion) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
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
        foreach ($policy->mandatory_requirements ?? [] as $requirement) {
            if (! is_array($requirement) || ! is_string($requirement['code'] ?? null) || ! is_string($requirement['type'] ?? null)) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
            }
            $result = $byCode[$requirement['code']] ?? null;
            $states[] = new AdmissionRequirementState(
                code: $requirement['code'],
                type: $requirement['type'],
                status: $result?->status ?? 'missing',
                mandatory: (bool) ($requirement['mandatory'] ?? true),
                verified: $result?->sourceId !== null,
                validUntil: $result?->validUntil?->toDateTimeImmutable(),
                evidenceType: $result?->sourceType,
                evidenceId: $result?->sourceId,
                medicalDetails: null,
            );
        }

        return $states;
    }
}
