<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Readiness;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\DTO\SafetyIncidentReadiness;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetyIncidentSnapshot;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class SafetyIncidentReadinessProbe implements ReportDefinitionReadinessProbe
{
    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === 'safety_incident_actions';
    }

    public function reportCodes(): array
    {
        return ['safety_incident_actions'];
    }

    public function inspect(ReportExecutionContext $context, ReportQuery $query): SafetyIncidentReadiness
    {
        $snapshot = SafetyIncidentSnapshot::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('definition_hash', $query->definition->definitionHash->value)
            ->where('formula_version', $query->definition->formulaVersion)
            ->where('query_hash', $query->queryHash->value)
            ->where('as_of', $query->asOf)
            ->latest('generated_at')
            ->first();
        if (! $snapshot instanceof SafetyIncidentSnapshot) {
            return new SafetyIncidentReadiness('unavailable', 0, 0, 0, 0, false, null, null, null);
        }
        $readySources = DB::table('report_source_sync_ledgers')
            ->where('organization_id', $context->scope->organizationId)
            ->whereIn('source_code', ['safety_subject_lifecycle', 'approved_workforce_attendance'])
            ->where('status', 'ready')
            ->where('gap_count', 0)
            ->where('unknown_count', 0)
            ->distinct()
            ->count('source_code');
        $ready = $readySources === 2
            && (int) $snapshot->eligible_count === (int) $snapshot->projected_count
            && (int) $snapshot->gap_count === 0
            && (int) $snapshot->unknown_count === 0
            && (bool) $snapshot->exposure_complete
            && $snapshot->stale_at > now();

        return new SafetyIncidentReadiness(
            $ready ? 'ready' : 'partial',
            (int) $snapshot->eligible_count,
            (int) $snapshot->projected_count,
            (int) $snapshot->gap_count,
            (int) $snapshot->unknown_count,
            (bool) $snapshot->exposure_complete,
            (string) $snapshot->input_hash,
            (string) $snapshot->output_hash,
            $ready ? DateTimeImmutable::createFromInterface($snapshot->generated_at) : null,
        );
    }
}
