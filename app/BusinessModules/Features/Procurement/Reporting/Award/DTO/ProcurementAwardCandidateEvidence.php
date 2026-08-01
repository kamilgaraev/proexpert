<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\DTO;

use App\BusinessModules\Features\Procurement\Reporting\Award\Support\ProcurementAwardCanonicalizer;
use InvalidArgumentException;

final readonly class ProcurementAwardCandidateEvidence
{
    public function __construct(
        public int $organizationId,
        public ?int $projectId,
        public int $purchaseRequestId,
        public int $supplierRequestId,
        public ?int $supplierRequestVersionId,
        public ?string $supplierRequestVersionHash,
        public int $proposalId,
        public ?int $proposalVersionId,
        public int $supplierPartyId,
        public ?string $proposalStatus,
        public ?string $proposalValidUntil,
        public ?string $versionContentHash,
        public ?string $subtotalAmount,
        public ?string $deliveryAmount,
        public ?string $vatAmount,
        public ?string $totalAmount,
        public ?string $comparisonTotal,
        public ?string $currency,
        public ?string $vatMode,
        public ?string $vatRate,
        public ?string $deliveryDueDate,
        public ?int $leadTimeDays,
        public array $requestLineCoverage,
        public bool $comparable,
        public array $exclusionCodes,
    ) {
        if ($organizationId < 1
            || $purchaseRequestId < 1
            || $supplierRequestId < 1
            || ($supplierRequestVersionId !== null && $supplierRequestVersionId < 1)
            || $proposalId < 1
            || ($proposalVersionId !== null && $proposalVersionId < 1)
            || $supplierPartyId < 1
            || ($projectId !== null && $projectId < 1)
            || ($versionContentHash !== null && preg_match('/^[a-f0-9]{64}$/D', $versionContentHash) !== 1)
            || ($supplierRequestVersionHash !== null
                && preg_match('/^[a-f0-9]{64}$/D', $supplierRequestVersionHash) !== 1)
            || ($comparable && ($comparisonTotal === null || $exclusionCodes !== []))) {
            throw new InvalidArgumentException('procurement_award_candidate_invalid');
        }
    }

    public function canonicalPayload(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'project_id' => $this->projectId,
            'purchase_request_id' => $this->purchaseRequestId,
            'supplier_request_id' => $this->supplierRequestId,
            'supplier_request_version_id' => $this->supplierRequestVersionId,
            'supplier_request_version_hash' => $this->supplierRequestVersionHash,
            'proposal_id' => $this->proposalId,
            'proposal_version_id' => $this->proposalVersionId,
            'supplier_party_id' => $this->supplierPartyId,
            'proposal_status' => $this->proposalStatus,
            'proposal_valid_until' => $this->proposalValidUntil,
            'version_content_hash' => $this->versionContentHash,
            'subtotal_amount' => $this->subtotalAmount,
            'delivery_amount' => $this->deliveryAmount,
            'vat_amount' => $this->vatAmount,
            'total_amount' => $this->totalAmount,
            'comparison_total' => $this->comparisonTotal,
            'currency' => $this->currency,
            'vat_mode' => $this->vatMode,
            'vat_rate' => $this->vatRate,
            'delivery_due_date' => $this->deliveryDueDate,
            'lead_time_days' => $this->leadTimeDays,
            'request_line_coverage' => $this->requestLineCoverage,
            'comparable' => $this->comparable,
            'exclusion_codes' => $this->exclusionCodes,
        ];
    }

    public function contentHash(): string
    {
        $coverage = $this->requestLineCoverage === [] ? null : implode(';', array_map(
            static fn (array $line): string => implode(',', [
                $line['supplier_request_line_id'],
                $line['required_quantity'] ?? '',
                $line['required_unit'] ?? '',
                $line['covered_quantity'] ?? '',
                $line['covered_unit'] ?? '',
                ($line['covered'] ?? false) ? '1' : '0',
            ]),
            $this->requestLineCoverage,
        ));

        return ProcurementAwardCanonicalizer::framedHash([
            $this->organizationId,
            $this->projectId,
            $this->purchaseRequestId,
            $this->supplierRequestId,
            $this->supplierRequestVersionId,
            $this->supplierRequestVersionHash,
            $this->proposalId,
            $this->proposalVersionId,
            $this->supplierPartyId,
            $this->proposalStatus,
            $this->proposalValidUntil,
            $this->versionContentHash,
            $this->subtotalAmount,
            $this->deliveryAmount,
            $this->vatAmount,
            $this->totalAmount,
            $this->comparisonTotal,
            $this->currency,
            $this->vatMode,
            $this->vatRate,
            $this->deliveryDueDate,
            $this->leadTimeDays,
            $coverage,
            $this->comparable,
            implode(',', $this->exclusionCodes),
        ]);
    }
}
