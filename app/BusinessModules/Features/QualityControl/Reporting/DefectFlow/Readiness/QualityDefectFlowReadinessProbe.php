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
            ->where('as_of', $query->asOf)
            ->latest('generated_at')
            ->first();
        if (! $snapshot instanceof QualityDefectFlowSnapshot) {
            return new QualityDefectFlowReadiness('unavailable', 0, 0, 0, 0, null, null, null);
        }
        $ready = (int) $snapshot->eligible_count === (int) $snapshot->projected_count
            && (int) $snapshot->gap_count === 0
            && (int) $snapshot->unknown_count === 0
            && $snapshot->stale_at > now();

        return new QualityDefectFlowReadiness(
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
