<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services;

use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\LookaheadReadinessPolicyVersion;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class LookaheadReadinessPolicyVersionWriter
{
    public function publish(
        LookaheadReadinessPolicyDefinition $definition,
    ): LookaheadReadinessPolicyVersion {
        return DB::transaction(function () use ($definition): LookaheadReadinessPolicyVersion {
            DB::select(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                [$this->streamKey($definition->organizationId, $definition->projectId)],
            );

            $hash = $definition->sourceHash();
            $existing = $this->scope($definition)
                ->where('source_hash', $hash)
                ->first();
            if ($existing !== null) {
                return $this->hydrate($existing, $definition);
            }

            $version = (int) $this->scope($definition)->max('version') + 1;
            $id = DB::table('lookahead_reporting_policy_versions')->insertGetId([
                'organization_id' => $definition->organizationId,
                'project_id' => $definition->projectId,
                'horizon_days' => $definition->horizonDays,
                'eligible_task_statuses' => json_encode(
                    $definition->eligibleTaskStatuses,
                    JSON_THROW_ON_ERROR,
                ),
                'mandatory_constraint_types' => json_encode(
                    $definition->mandatoryConstraintTypes,
                    JSON_THROW_ON_ERROR,
                ),
                'hard_severities' => json_encode($definition->hardSeverities, JSON_THROW_ON_ERROR),
                'waiver_evidence_required' => $definition->waiverEvidenceRequired,
                'effective_from' => $definition->effectiveFrom,
                'effective_until' => $definition->effectiveUntil,
                'timezone' => $definition->timezone,
                'source_hash' => $hash,
                'version' => $version,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $record = DB::table('lookahead_reporting_policy_versions')->find($id);
            if ($record === null) {
                throw new InvalidArgumentException('lookahead_policy_publish_failed');
            }

            return $this->hydrate($record, $definition);
        }, 5);
    }

    private function scope(LookaheadReadinessPolicyDefinition $definition): Builder
    {
        return DB::table('lookahead_reporting_policy_versions')
            ->where('organization_id', $definition->organizationId)
            ->when(
                $definition->projectId === null,
                static fn (Builder $query): Builder => $query->whereNull('project_id'),
                static fn (Builder $query): Builder => $query->where('project_id', $definition->projectId),
            );
    }

    private function hydrate(
        object $record,
        LookaheadReadinessPolicyDefinition $definition,
    ): LookaheadReadinessPolicyVersion {
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

    private function streamKey(int $organizationId, ?int $projectId): string
    {
        return 'lookahead-policy:'.$organizationId.':'.($projectId ?? 'default');
    }
}
