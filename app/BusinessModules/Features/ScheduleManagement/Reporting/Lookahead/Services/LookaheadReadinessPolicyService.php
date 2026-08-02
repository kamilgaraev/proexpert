<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services;

use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\LookaheadReadinessPolicySet;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\LookaheadReadinessPolicyVersion;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class LookaheadReadinessPolicyService
{
    public function active(
        int $organizationId,
        array $projectIds,
        DateTimeImmutable $asOf,
    ): LookaheadReadinessPolicyVersion {
        if (count($projectIds) !== 1) {
            throw new InvalidArgumentException('lookahead_single_project_policy_required');
        }

        return $this->activeForProjects($organizationId, $projectIds, $asOf)
            ->forProject((int) $projectIds[0]);
    }

    public function activeForProjects(
        int $organizationId,
        array $projectIds,
        DateTimeImmutable $asOf,
    ): LookaheadReadinessPolicySet {
        if ($organizationId < 1 || $projectIds === []) {
            throw new InvalidArgumentException('lookahead_policy_scope_invalid');
        }

        $records = DB::table('lookahead_readiness_policy_versions')
            ->where('organization_id', $organizationId)
            ->where(function ($query) use ($projectIds): void {
                $query->whereNull('project_id')->orWhereIn('project_id', $projectIds);
            })
            ->where('effective_from', '<=', $asOf)
            ->where(function ($query) use ($asOf): void {
                $query->whereNull('effective_until')->orWhere('effective_until', '>=', $asOf);
            })
            ->orderByDesc('version')
            ->get();
        if ($records->isEmpty()) {
            throw new InvalidArgumentException('lookahead_policy_unavailable');
        }

        return new LookaheadReadinessPolicySet(
            $records->map(fn (object $record): LookaheadReadinessPolicyVersion => $this->hydrate($record))->all(),
            array_values(array_map('intval', $projectIds)),
        );
    }

    private function jsonList(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new InvalidArgumentException('lookahead_policy_payload_invalid');
        }

        return $decoded;
    }

    private function hydrate(object $record): LookaheadReadinessPolicyVersion
    {
        $definition = new LookaheadReadinessPolicyDefinition(
            organizationId: (int) $record->organization_id,
            projectId: $record->project_id === null ? null : (int) $record->project_id,
            horizonDays: (int) $record->horizon_days,
            eligibleTaskStatuses: $this->jsonList($record->eligible_task_statuses),
            mandatoryConstraintTypes: $this->jsonList($record->mandatory_constraint_types),
            hardSeverities: $this->jsonList($record->hard_severities),
            waiverEvidenceRequired: (bool) $record->waiver_evidence_required,
            effectiveFrom: new DateTimeImmutable((string) $record->effective_from),
            effectiveUntil: $record->effective_until === null
                ? null
                : new DateTimeImmutable((string) $record->effective_until),
            timezone: (string) $record->timezone,
        );
        if (! hash_equals($definition->sourceHash(), (string) $record->source_hash)) {
            throw new InvalidArgumentException('lookahead_policy_source_hash_mismatch');
        }

        return new LookaheadReadinessPolicyVersion(
            version: (int) $record->version,
            organizationId: $definition->organizationId,
            horizonDays: $definition->horizonDays,
            eligibleTaskStatuses: $definition->eligibleTaskStatuses,
            mandatoryConstraintTypes: $definition->mandatoryConstraintTypes,
            hardSeverities: $definition->hardSeverities,
            waiverEvidenceRequired: $definition->waiverEvidenceRequired,
            effectiveFrom: $definition->effectiveFrom,
            effectiveUntil: $definition->effectiveUntil,
            timezone: $definition->timezone,
            sourceHash: $definition->sourceHash(),
            projectId: $definition->projectId,
            policyId: (int) $record->id,
        );
    }
}
