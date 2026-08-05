<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Readiness;

use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingAllocationCheckpointSource;
use App\BusinessModules\Core\MultiOrganization\Reporting\IntercompanyContractFlowCandidateContract;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingAllocationCheckpointSourceAssembler;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceReadiness;
use App\Support\Reporting\ReportSourceReadinessFactory;
use App\Support\Reporting\StableReportingSourceView;
use InvalidArgumentException;

final readonly class IntercompanyContractFlowReadinessProbe implements ReportSourceReadinessProbe
{
    public function __construct(
        private HoldingAllocationCheckpointSourceAssembler $sources,
        private ReportSourceReadinessFactory $readiness,
        private StableReportingSourceView $stableView,
    ) {}

    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === IntercompanyContractFlowCandidateContract::CODE
            && $definition->formulaVersion === IntercompanyContractFlowCandidateContract::FORMULA_VERSION;
    }

    public function reportCodes(): array
    {
        return [IntercompanyContractFlowCandidateContract::CODE];
    }

    public function inspect(
        ReportExecutionContext $context,
        ReportQuery $query,
    ): ReportSourceReadiness {
        return $this->stableView->capture(
            fn (): ReportSourceReadiness => $this->inspectWithinStableView($context, $query),
            5,
        );
    }

    private function inspectWithinStableView(
        ReportExecutionContext $context,
        ReportQuery $query,
    ): ReportSourceReadiness {
        try {
            $batch = $this->sources->assemble($context->scope, $query);
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (InvalidArgumentException) {
            $gap = [[
                'kind' => 'holding_allocation_checkpoint',
                'reason' => 'source_unavailable',
            ]];

            return $this->readiness->make(
                $gap,
                [],
                1,
                0,
                'holding-allocation-checkpoint:unavailable',
            );
        }

        $projected = array_map(
            static fn (HoldingAllocationCheckpointSource $source): array => $source->canonicalIdentity(),
            $batch->sources,
        );
        $eligible = [...$projected, ...$batch->gaps];

        return $this->readiness->make(
            $eligible,
            $projected,
            count($batch->gaps),
            0,
            $batch->watermark,
        );
    }
}
