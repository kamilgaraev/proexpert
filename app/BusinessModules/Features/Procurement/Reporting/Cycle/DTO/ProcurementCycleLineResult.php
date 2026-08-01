<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO;

use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementCycleStage;

final readonly class ProcurementCycleLineResult
{
    public function __construct(
        public int $organizationId,
        public ?int $projectId,
        public int $purchaseRequestId,
        public int $purchaseRequestLineId,
        public array $dimensions,
        public array $solicitedSupplierIds,
        public ?int $awardedSupplierPartyId,
        public ?string $awardedAmount,
        public ?string $currency,
        public string $outcome,
        public ?ProcurementCycleStage $currentStage,
        public ?string $startCohortDate,
        public ?string $outcomeCohortDate,
        public array $boundaryTimes,
        public array $stageMetrics,
        public ?int $openAgeSeconds,
        public ?int $totalCycleSeconds,
        public ?int $timeToCancellationSeconds,
        public bool $totalSlaEligible,
        public ?bool $totalSlaMet,
        public string $qualityStatus,
        public array $gapCodes,
    ) {}

    public function row(): array
    {
        return [
            ...$this->dimensions,
            'row_key' => 'procurement-line:'.$this->purchaseRequestLineId,
            'organization_id' => $this->organizationId,
            'project_id' => $this->projectId,
            'purchase_request_id' => $this->purchaseRequestId,
            'purchase_request_line_id' => $this->purchaseRequestLineId,
            'solicited_supplier_ids' => $this->solicitedSupplierIds,
            'solicited_supplier_count' => count($this->solicitedSupplierIds),
            'awarded_supplier_party_id' => $this->awardedSupplierPartyId,
            'awarded_amount' => $this->awardedAmount,
            'currency' => $this->currency,
            'outcome' => $this->outcome,
            'current_stage' => $this->currentStage?->value,
            'start_cohort_date' => $this->startCohortDate,
            'outcome_cohort_date' => $this->outcomeCohortDate,
            'boundary_times' => $this->boundaryTimes,
            'stage_metrics' => array_map(
                static fn (ProcurementCycleMetric $metric): array => $metric->toArray(),
                $this->stageMetrics,
            ),
            'open_age_seconds' => $this->openAgeSeconds,
            'total_cycle_seconds' => $this->totalCycleSeconds,
            'time_to_cancellation_seconds' => $this->timeToCancellationSeconds,
            'total_sla_eligible' => $this->totalSlaEligible,
            'total_sla_met' => $this->totalSlaMet,
            'total_sla_numerator' => $this->totalSlaEligible && $this->totalSlaMet === true ? 1 : 0,
            'total_sla_denominator' => $this->totalSlaEligible ? 1 : 0,
            'quality_status' => $this->qualityStatus,
            'gap_codes' => $this->gapCodes,
        ];
    }

    public function stageDrillRows(): array
    {
        $rows = [];
        foreach (ProcurementCycleStage::cases() as $stage) {
            $rows[] = [
                'row_key' => 'procurement-line:'.$this->purchaseRequestLineId,
                'stage' => $stage->value,
                ...$this->stageMetrics[$stage->value]->toArray(),
            ];
        }

        return $rows;
    }
}
