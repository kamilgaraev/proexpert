<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Readiness;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\DTO\WorkforceAdmissionReadiness;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Models\SafetyAdmissionSnapshot;
use DateTimeImmutable;

final readonly class WorkforceAdmissionReadinessProbe implements ReportDefinitionReadinessProbe
{
    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === 'workforce_admission';
    }

    public function reportCodes(): array
    {
        return ['workforce_admission'];
    }

    public function inspect(ReportExecutionContext $context, ReportQuery $query): WorkforceAdmissionReadiness
    {
        $snapshot = SafetyAdmissionSnapshot::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('definition_hash', $query->definition->definitionHash->value)
            ->where('formula_version', $query->definition->formulaVersion)
            ->where('snapshot_date', $query->asOf->format('Y-m-d'))
            ->latest('generated_at')
            ->first();
        if (! $snapshot instanceof SafetyAdmissionSnapshot) {
            return new WorkforceAdmissionReadiness('unavailable', 0, 0, 0, 0, null, null, null);
        }
        $ready = (int) $snapshot->eligible_count === (int) $snapshot->projected_count
            && (int) $snapshot->gap_count === 0
            && (int) $snapshot->unknown_count === 0
            && $snapshot->stale_at > now();

        return new WorkforceAdmissionReadiness(
            $ready ? 'ready' : 'partial',
            (int) $snapshot->eligible_count,
            (int) $snapshot->projected_count,
            (int) $snapshot->gap_count,
            (int) $snapshot->unknown_count,
            (string) $snapshot->source_hash,
            hash('sha256', (string) $snapshot->id.':'.(string) $snapshot->source_hash),
            $ready ? DateTimeImmutable::createFromInterface($snapshot->generated_at) : null,
        );
    }
}
