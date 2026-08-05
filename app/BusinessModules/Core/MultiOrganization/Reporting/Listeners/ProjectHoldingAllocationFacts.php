<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Listeners;

use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingAllocationFactProjector;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingAllocationContextResolver;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingContractDimensionResolver;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingHierarchyResolver;
use App\BusinessModules\Core\Payments\Events\PaymentDocumentPaid;
use App\Enums\CurrencyCode;
use App\Models\Contract;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use InvalidArgumentException;

final readonly class ProjectHoldingAllocationFacts implements ShouldQueueAfterCommit
{
    public function __construct(
        private HoldingAllocationFactProjector $projector,
        private HoldingHierarchyResolver $hierarchies,
        private HoldingContractDimensionResolver $contractDimensions,
        private HoldingAllocationContextResolver $allocationContexts,
    ) {}

    public function handle(PaymentDocumentPaid $event): void
    {
        $document = $event->document;
        $invoiceableType = $event->invoiceableType ?? $document->invoiceable_type;
        $contractId = $invoiceableType === Contract::class
            ? (int) ($event->invoiceableId ?? $document->invoiceable_id)
            : 0;
        $projectId = (int) ($event->projectId ?? $document->project_id ?? 0);
        $organizationId = (int) ($event->organizationId ?? $document->organization_id ?? 0);
        if (min($contractId, $projectId, $organizationId) < 1) {
            return;
        }

        $sourceId = $event->transactionId ?? (int) $document->getKey();
        $sourceType = $event->transactionId === null ? 'payment_document' : 'transaction';
        $sourceVersion = $event->transactionId ?? (int) $document->getKey();
        $recognizedAt = $event->recognizedAt ?? $document->paid_at;
        $eventCurrency = $event->currency ?? $document->currency;
        $currencyValid = is_string($eventCurrency)
            && CurrencyCode::tryFrom(mb_strtoupper($eventCurrency)) !== null;
        if ($recognizedAt === null || ! $currencyValid) {
            $this->projector->recordGap([
                'organization_id' => $organizationId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_version' => $sourceVersion,
                'monetary_basis' => 'cash',
                'business_effective_at' => $recognizedAt ?? $document->created_at,
            ], array_values(array_filter([
                $recognizedAt === null ? 'recognized_on' : null,
                $currencyValid ? null : 'currency',
            ])));

            return;
        }

        $missing = [];
        try {
            $hierarchy = $this->hierarchies->resolveAt($organizationId, $recognizedAt);
        } catch (InvalidArgumentException $exception) {
            $hierarchy = null;
            $missing[] = $exception->getMessage() === 'holding_reporting_context_historical_gap'
                ? 'hierarchy_coverage'
                : 'hierarchy';
        }
        try {
            $dimension = $this->contractDimensions->resolve($organizationId, $contractId, $recognizedAt);
        } catch (InvalidArgumentException $exception) {
            $dimension = null;
            $missing[] = $exception->getMessage() === 'holding_reporting_context_historical_gap'
                ? 'contract_dimension_coverage'
                : 'contract_dimensions';
        }
        if ($dimension !== null && mb_strtoupper((string) $eventCurrency) !== $dimension->currency) {
            $missing[] = 'currency_mismatch';
        }
        try {
            $allocation = $this->allocationContexts->resolve(
                $organizationId,
                $contractId,
                $projectId,
                $recognizedAt,
                requirePercentage: true,
            );
        } catch (InvalidArgumentException $exception) {
            $allocation = null;
            $missing[] = $exception->getMessage() === 'holding_reporting_context_historical_gap'
                ? 'allocation_context_coverage'
                : 'allocation_context';
        }
        if ($missing !== []) {
            $this->projector->recordGap([
                'organization_id' => $organizationId,
                'holding_id' => $hierarchy?->holdingId,
                'hierarchy_version' => $hierarchy?->version,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_version' => $sourceVersion,
                'monetary_basis' => 'cash',
                'business_effective_at' => $recognizedAt,
            ], $missing);

            return;
        }
        if ($hierarchy === null || $dimension === null || $allocation === null) {
            return;
        }
        $paymentAmountMinor = BigDecimal::of((string) $event->amount)
            ->multipliedBy(100)
            ->toScale(0, RoundingMode::HalfUp)
            ->toInt();
        $source = [
            'organization_id' => $organizationId,
            'holding_id' => $hierarchy->holdingId,
            'hierarchy_version' => $hierarchy->version,
            'hierarchy_organization_ids' => $hierarchy->organizationIds,
            'contributor_organization_id' => $organizationId,
            'counterparty_organization_id' => $dimension->counterpartyOrganizationId,
            'project_id' => $projectId,
            'contract_id' => $contractId,
            'contractor_id' => $dimension->contractorId,
            'contract_status' => $dimension->contractStatus,
            'work_type_category' => $dimension->workTypeCategory,
            'contract_dimension_hash' => $dimension->evidenceHash,
            'allocation_id' => $allocation->allocationId,
            'linked_parent_allocation_id' => null,
            'linked_incoming_minor' => null,
            'linked_outgoing_minor' => null,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_version' => $sourceVersion,
            'monetary_basis' => 'cash',
            'allocated_amount_minor' => null,
            'allocated_percentage' => $allocation->allocatedPercentage,
            'contract_amount_minor' => $paymentAmountMinor,
            'currency' => $dimension->currency,
            'currency_source' => 'payment_event_contract_dimension',
            'tax_basis' => 'payment_amount',
            'recognized_on' => $recognizedAt->format('Y-m-d'),
            'business_effective_at' => $recognizedAt,
            'source_refs' => [[
                'type' => 'payment_transaction',
                'id' => $sourceId,
            ], [
                'type' => 'allocation_context',
                'id' => $allocation->eventId,
                'hash' => $allocation->evidenceHash,
            ], [
                'type' => 'contract_dimension',
                'id' => $dimension->eventId,
                'hash' => $dimension->evidenceHash,
            ], [
                'type' => 'organization_hierarchy',
                'hash' => $hierarchy->version,
                'evidence_hashes' => $hierarchy->evidenceHashes,
            ]],
        ];
        $this->projector->persist($this->projector->project($source), $source);
    }
}
