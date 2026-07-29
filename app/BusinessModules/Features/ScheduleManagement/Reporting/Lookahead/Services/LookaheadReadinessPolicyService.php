<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services;

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
        if ($organizationId < 1 || $projectIds === []) {
            throw new InvalidArgumentException('lookahead_policy_scope_invalid');
        }

        $record = DB::table('lookahead_readiness_policy_versions')
            ->where('organization_id', $organizationId)
            ->where(function ($query) use ($projectIds): void {
                $query->whereNull('project_id')->orWhereIn('project_id', $projectIds);
            })
            ->where('effective_from', '<=', $asOf)
            ->where(function ($query) use ($asOf): void {
                $query->whereNull('effective_until')->orWhere('effective_until', '>=', $asOf);
            })
            ->orderByRaw('project_id is null')
            ->orderByDesc('version')
            ->first();
        if ($record === null) {
            throw new InvalidArgumentException('lookahead_policy_unavailable');
        }

        return new LookaheadReadinessPolicyVersion(
            version: (int) $record->version,
            organizationId: (int) $record->organization_id,
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
            sourceHash: (string) $record->source_hash,
            projectId: $record->project_id === null ? null : (int) $record->project_id,
            policyId: (int) $record->id,
        );
    }

    private function jsonList(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new InvalidArgumentException('lookahead_policy_payload_invalid');
        }

        return $decoded;
    }
}
