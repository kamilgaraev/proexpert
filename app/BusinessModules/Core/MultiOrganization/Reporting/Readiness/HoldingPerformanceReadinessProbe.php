<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Readiness;

use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingAllocationCheckpointSource;
use App\BusinessModules\Core\MultiOrganization\Reporting\HoldingPerformanceCandidateContract;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingAllocationCheckpointSourceAssembler;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPerformanceImmutableEventSource;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPerformanceProjectionCoverageInspector;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceReadiness;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Support\Reporting\ReportSourceReadinessFactory;
use App\Support\Reporting\StableReportingSourceView;
use InvalidArgumentException;

final readonly class HoldingPerformanceReadinessProbe implements ReportSourceReadinessProbe
{
    public function __construct(
        private HoldingAllocationCheckpointSourceAssembler $sources,
        private HoldingPerformanceImmutableEventSource $events,
        private HoldingPerformanceProjectionCoverageInspector $projectionCoverage,
        private ReportSourceReadinessFactory $readiness,
        private StableReportingSourceView $stableView,
    ) {}

    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === HoldingPerformanceCandidateContract::CODE
            && $definition->formulaVersion === HoldingPerformanceCandidateContract::FORMULA_VERSION;
    }

    public function reportCodes(): array
    {
        return [HoldingPerformanceCandidateContract::CODE];
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
            $coverageStartedAt = $this->events->coverageStartedAt(
                $this->sources->coverageStartedAt($query->asOf),
                $context->scope->timezone,
            );
            $this->events->assertPeriodCovered(
                $query->filters->values,
                $coverageStartedAt,
                $context->scope->timezone,
            );
            $openingBoundary = $this->sources->openingBoundary($query);
            $batch = $this->sources->assembleOpeningState($context->scope, $query, $openingBoundary);
            $recordedCutoff = now()->toImmutable();
            $coverage = $this->projectionCoverage->inspect(
                $batch->hierarchy->holdingId,
                $batch->hierarchy->organizationIds,
                $context->scope->projectIds,
                $coverageStartedAt,
                $query->asOf,
                $recordedCutoff,
                requirePersisted: false,
            );
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (InvalidArgumentException) {
            return $this->readiness->make(
                [['kind' => 'holding_performance_source', 'reason' => 'source_unavailable']],
                [],
                1,
                0,
                'holding-performance:unavailable',
            );
        }

        $projected = array_map(
            static fn (HoldingAllocationCheckpointSource $source): array => $source->canonicalIdentity(),
            $batch->sources,
        );
        $eligible = [...$projected, ...$batch->gaps];
        foreach ($coverage->eligibleActVersionIds as $id) {
            $eligible[] = ['kind' => 'holding_accepted_work_event', 'id' => $id];
        }
        foreach ($coverage->projectedActVersionIds as $id) {
            $projected[] = ['kind' => 'holding_accepted_work_event', 'id' => $id];
        }
        foreach ($coverage->eligiblePaymentVersionIds as $id) {
            $eligible[] = ['kind' => 'holding_payment_transaction_event', 'id' => $id];
        }
        foreach ($coverage->projectedPaymentVersionIds as $id) {
            $projected[] = ['kind' => 'holding_payment_transaction_event', 'id' => $id];
        }

        return $this->readiness->make(
            $eligible,
            $projected,
            count($batch->gaps) + $coverage->gapCount(),
            0,
            hash('sha256', CanonicalJson::encode([
                'checkpoint' => $batch->watermark,
                'projection' => $coverage->watermark,
            ])),
        );
    }
}
