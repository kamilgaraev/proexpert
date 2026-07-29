<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Readiness;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\BusinessModules\Features\Procurement\Reporting\Award\Models\SupplierAwardDecisionVersion;
use App\Support\Reporting\SourceReadinessResult;
use DateTimeImmutable;

final readonly class SupplierAwardReadinessProbe implements ReportDefinitionReadinessProbe
{
    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === 'supplier_award_competitiveness'
            && $definition->formulaVersion === 'supplier-award.v1';
    }

    public function assertReady(ReportExecutionContext $context, ReportQuery $query): void
    {
        $this->inspect($context, $query)->assertReady('supplier_award_competitiveness');
    }

    public function inspect(ReportExecutionContext $context, ReportQuery $query): SourceReadinessResult
    {
        $eligible = SupplierProposalDecision::query()
            ->where('organization_id', $context->scope->organizationId)
            ->whereNotNull('selected_at')
            ->where('selected_at', '<=', $query->asOf)
            ->count();
        $versions = SupplierAwardDecisionVersion::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('selected_at', '<=', $query->asOf);
        $projected = (clone $versions)->distinct()->count('decision_id');
        $invalidVersions = (clone $versions)
            ->where(function ($builder): void {
                $builder->whereNull('selected_proposal_version_id')
                    ->orWhereNull('cheapest_proposal_version_id')
                    ->orWhereNull('median_proposal_version_id');
            })
            ->count();

        return new SourceReadinessResult(
            $eligible,
            $projected,
            max(0, $eligible - $projected),
            0,
            $invalidVersions,
            (clone $versions)->whereRaw('LENGTH(source_hash) <> 64')->count(),
            new DateTimeImmutable,
        );
    }
}
