<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Readiness;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DTO\QualityDefectFlowReadiness;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models\QualityDefectFlowSnapshot;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class QualityDefectFlowReadinessProbe implements ReportDefinitionReadinessProbe
{
    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === 'quality_defect_flow';
    }

    public function reportCodes(): array
    {
        return ['quality_defect_flow'];
    }

    public function inspect(ReportExecutionContext $context, ReportQuery $query): QualityDefectFlowReadiness
    {
        $snapshot = QualityDefectFlowSnapshot::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('definition_hash', $query->definition->definitionHash->value)
            ->where('formula_version', $query->definition->formulaVersion)
            ->where('query_hash', $query->queryHash->value)
            ->where('as_of', $query->asOf)
            ->latest('generated_at')
            ->first();
        if (! $snapshot instanceof QualityDefectFlowSnapshot) {
            return new QualityDefectFlowReadiness('unavailable', 0, 0, 0, 0, null, null, null);
        }
        $sourceReady = DB::table('report_source_sync_ledgers')
            ->where('organization_id', $context->scope->organizationId)
            ->where('source_code', 'quality_defect_status_history')
            ->where('status', 'ready')
            ->where('gap_count', 0)
            ->where('unknown_count', 0)
            ->whereColumn('completed_owner_checksum', 'owner_checksum')
            ->whereColumn('cursor', 'target_cursor')
            ->exists();
        $ready = $sourceReady
            && (int) $snapshot->eligible_count === (int) $snapshot->projected_count
            && (int) $snapshot->gap_count === 0
            && (int) $snapshot->unknown_count === 0
            && (int) $snapshot->mature_cohort_count > 0
            && $snapshot->stale_at > now();

        return new QualityDefectFlowReadiness(
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
