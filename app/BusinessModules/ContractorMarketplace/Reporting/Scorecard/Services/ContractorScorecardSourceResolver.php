<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Services;

use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DTO\ContractorScorecardSourceTuple;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Providers\SupplyReliabilityReportProvider;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Providers\QualityDefectFlowReportProvider;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Providers\SafetyIncidentActionsReportProvider;
use App\BusinessModules\Features\ScheduleManagement\Reporting\BaselineScheduleVarianceProvider;
use Throwable;

final readonly class ContractorScorecardSourceResolver
{
    private const REPORT_CODES = [
        'baseline_schedule_variance',
        'supply_reliability',
        'quality_defect_flow',
        'safety_incident_actions',
    ];

    public function __construct(
        private ContractorReviewSnapshotResolver $reviews,
        private ReportDefinitionRegistry $definitions,
        private BaselineScheduleVarianceProvider $baseline,
        private SupplyReliabilityReportProvider $supply,
        private QualityDefectFlowReportProvider $quality,
        private SafetyIncidentActionsReportProvider $safety,
    ) {}

    public function resolve(
        ReportExecutionContext $context,
        ReportQuery $query,
        string $periodFrom,
        string $periodTo,
    ): ContractorScorecardSourceTuple {
        try {
            $refs = [];
            foreach (self::REPORT_CODES as $code) {
                $refs[$code] = $this->materializeOwner(
                    $context,
                    $query,
                    $code,
                    $periodFrom,
                    $periodTo,
                );
            }
            $tuple = new ContractorScorecardSourceTuple(
                $refs['baseline_schedule_variance'],
                $refs['supply_reliability'],
                $refs['quality_defect_flow'],
                $refs['safety_incident_actions'],
                $this->reviews->resolve($query),
            );
            $tuple->assertCompatible($context, $query);

            return $tuple;
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_SOURCE_UNAVAILABLE,
                [],
                $exception,
            );
        }
    }

    private function materializeOwner(
        ReportExecutionContext $context,
        ReportQuery $scorecardQuery,
        string $code,
        string $periodFrom,
        string $periodTo,
    ): ReportSnapshotRef {
        $definition = $this->definitions->published($code)->payload();
        $ownerQuery = new ReportQuery(
            $definition,
            $scorecardQuery->scope,
            new ReportFilterSet($this->ownerFilters($code, $scorecardQuery, $periodFrom, $periodTo)),
            [],
            $scorecardQuery->asOf,
            $scorecardQuery->locale,
        );
        $provider = $this->provider($code);
        $snapshot = $provider->materialize(
            $context,
            $ownerQuery,
            new ReportProgress(0),
        );
        $quality = $provider->result($context, $snapshot)->quality;
        if ($quality->status !== ReportQualityStatus::COMPLETE
            || $quality->unmatchedCount !== 0
            || $quality->reconciliation === ReportReconciliationStatus::MISMATCH
            || $quality->unknownMetrics !== []
        ) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }
        if ($snapshot->scope->canonicalIdentity() !== $scorecardQuery->scope->canonicalIdentity()) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }

        return new ReportSnapshotRef(
            $snapshot->kind,
            $snapshot->id,
            $snapshot->scope,
            $snapshot->definitionHash,
            $snapshot->formulaVersion,
            $snapshot->sourceHash,
            $snapshot->generatedAt,
            $snapshot->staleAt,
            array_merge($snapshot->watermarks, [
                'as_of' => $scorecardQuery->asOf->format(DATE_ATOM),
                'cohort_key' => $scorecardQuery->filters->values['cohort'],
                'project_ids' => $scorecardQuery->scope->projectIds,
            ]),
            $snapshot->classification,
            $snapshot->seal,
            $snapshot->materializedSourceHash,
        );
    }

    private function provider(string $code): \App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider
    {
        return match ($code) {
            'baseline_schedule_variance' => $this->baseline,
            'supply_reliability' => $this->supply,
            'quality_defect_flow' => $this->quality,
            'safety_incident_actions' => $this->safety,
            default => throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE),
        };
    }

    private function ownerFilters(
        string $code,
        ReportQuery $query,
        string $periodFrom,
        string $periodTo,
    ): array {
        return match ($code) {
            'baseline_schedule_variance' => ['as_of' => $query->asOf->format('Y-m-d')],
            'supply_reliability' => ['period_start' => $periodFrom, 'period_end' => $periodTo],
            'quality_defect_flow', 'safety_incident_actions' => [
                'period_from' => $periodFrom,
                'period_to' => $periodTo,
            ],
            default => throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE),
        };
    }
}
