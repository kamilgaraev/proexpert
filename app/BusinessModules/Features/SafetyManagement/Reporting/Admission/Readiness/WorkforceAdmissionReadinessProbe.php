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
use Illuminate\Support\Facades\DB;

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
            ->where('query_hash', $query->queryHash->value)
            ->where('snapshot_date', $query->asOf->format('Y-m-d'))
            ->latest('generated_at')
            ->first();
        if (! $snapshot instanceof SafetyAdmissionSnapshot) {
            return new WorkforceAdmissionReadiness('unavailable', 0, 0, 0, 0, null, null, null);
        }
        $sourceReady = DB::table('report_source_sync_ledgers')
            ->where('organization_id', $context->scope->organizationId)
            ->where('source_code', 'safety_site_workforce_assignments')
            ->where('status', 'ready')
            ->where('gap_count', 0)
            ->where('unknown_count', 0)
            ->exists();
        $ready = $sourceReady
            && (int) $snapshot->eligible_count === (int) $snapshot->projected_count
            && (int) $snapshot->gap_count === 0
            && (int) $snapshot->unknown_count === 0
            && $snapshot->stale_at > now();

        return new WorkforceAdmissionReadiness(
            $ready ? 'ready' : 'partial',
            (int) $snapshot->eligible_count,
            (int) $snapshot->projected_count,
            (int) $snapshot->gap_count,
            (int) $snapshot->unknown_count,
            (string) $snapshot->input_hash,
            (string) $snapshot->output_hash,
            $ready ? DateTimeImmutable::createFromInterface($snapshot->generated_at) : null,
        );
    }
}
