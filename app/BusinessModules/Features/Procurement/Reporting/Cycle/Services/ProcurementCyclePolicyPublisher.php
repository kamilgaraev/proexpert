<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Services;

use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCyclePolicyDefinition;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementCyclePolicyVersion;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class ProcurementCyclePolicyPublisher
{
    public function publish(
        ProcurementCyclePolicyDefinition $definition,
        ?int $publishedBy,
        DateTimeImmutable $publishedAt,
    ): ProcurementCyclePolicyVersion {
        if ($publishedBy !== null && $publishedBy < 1) {
            throw new InvalidArgumentException('procurement_cycle_policy_publisher_invalid');
        }

        return DB::transaction(function () use ($definition, $publishedBy, $publishedAt): ProcurementCyclePolicyVersion {
            $organization = DB::table('organizations')
                ->where('id', $definition->organizationId)
                ->lockForUpdate()
                ->first(['id']);
            if ($organization === null) {
                throw new LogicException('procurement_cycle_policy_organization_not_found');
            }

            if ($definition->projectId !== null) {
                $project = DB::table('projects')
                    ->where('id', $definition->projectId)
                    ->where('organization_id', $definition->organizationId)
                    ->lockForUpdate()
                    ->first(['id']);
                if ($project === null) {
                    throw new LogicException('procurement_cycle_policy_project_scope_mismatch');
                }
            }

            $canonicalHash = $definition->canonicalHash();
            $existing = ProcurementCyclePolicyVersion::query()
                ->where('organization_id', $definition->organizationId)
                ->where('canonical_hash', $canonicalHash)
                ->first();
            if ($existing instanceof ProcurementCyclePolicyVersion) {
                return $existing;
            }

            $scope = ProcurementCyclePolicyVersion::query()
                ->where('organization_id', $definition->organizationId);
            $definition->projectId === null
                ? $scope->whereNull('project_id')
                : $scope->where('project_id', $definition->projectId);
            $versionNumber = ((int) $scope->max('version_number')) + 1;
            $payload = $definition->canonicalPayload();
            $utc = new DateTimeZone('UTC');

            return ProcurementCyclePolicyVersion::query()->create([
                'organization_id' => $definition->organizationId,
                'project_id' => $definition->projectId,
                'version_number' => $versionNumber,
                'formula_version' => $definition->formulaVersion,
                'source_schema_version' => $definition->sourceSchemaVersion,
                'event_schema_version' => $definition->eventSchemaVersion,
                'calendar_version' => $definition->calendarVersion,
                'calendar_hash' => $definition->calendarHash(),
                'timezone' => $definition->timezone,
                'weekly_windows' => $payload['weekly_windows'],
                'exceptions' => $payload['exceptions'],
                'stage_sla_seconds' => $payload['stage_sla_seconds'],
                'total_sla_seconds' => $definition->totalSlaSeconds,
                'terminal_cancellation_policy' => $payload['terminal_cancellation_policy'],
                'effective_from' => $definition->effectiveFrom->setTimezone($utc),
                'effective_to' => $definition->effectiveTo?->setTimezone($utc),
                'canonical_hash' => $canonicalHash,
                'published_by' => $publishedBy,
                'published_at' => $publishedAt->setTimezone($utc),
                'created_at' => $publishedAt->setTimezone($utc),
            ]);
        });
    }
}
