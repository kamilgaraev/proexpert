<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Listeners;

use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingAllocationFactProjector;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingHierarchyResolver;
use App\BusinessModules\Core\Payments\Events\PaymentDocumentPaid;
use App\Models\Contract;
use App\Models\ContractProjectAllocation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

final readonly class ProjectHoldingAllocationFacts implements ShouldQueueAfterCommit
{
    public function __construct(
        private HoldingAllocationFactProjector $projector,
        private HoldingHierarchyResolver $hierarchies,
    ) {}

    public function handle(PaymentDocumentPaid $event): void
    {
        $document = $event->document;
        $contractId = $document->invoiceable_type === Contract::class
            ? (int) $document->invoiceable_id
            : 0;
        $projectId = (int) ($document->project_id ?? 0);
        $organizationId = (int) ($document->organization_id ?? 0);
        if (min($contractId, $projectId, $organizationId) < 1) {
            return;
        }

        $contract = Contract::query()->where('organization_id', $organizationId)->find($contractId);
        $allocation = ContractProjectAllocation::query()
            ->where('contract_id', $contractId)
            ->where('project_id', $projectId)
            ->where('is_active', true)
            ->first();
        if (! $contract instanceof Contract || ! $allocation instanceof ContractProjectAllocation) {
            return;
        }

        $contractAmount = BigDecimal::of((string) $contract->total_amount);
        $allocatedAmount = BigDecimal::of((string) $allocation->calculateAllocatedAmount());
        if ($contractAmount->isLessThanOrEqualTo(0) || $allocatedAmount->isNegative()) {
            return;
        }

        $percentage = (string) $allocatedAmount
            ->multipliedBy(100)
            ->dividedBy($contractAmount, 8, RoundingMode::HalfUp);
        $sourceId = $event->transactionId ?? (int) $document->getKey();
        $sourceType = $event->transactionId === null ? 'payment_document' : 'transaction';
        $paymentAmountMinor = BigDecimal::of((string) $event->amount)
            ->multipliedBy(100)
            ->toScale(0, RoundingMode::HalfUp)
            ->toInt();
        $sourceVersion = $event->transactionId ?? (int) $document->getKey();
        $recognizedAt = $event->recognizedAt ?? $document->paid_at;
        if ($recognizedAt === null || ! is_string($document->currency) || preg_match('/^[A-Z]{3}$/D', mb_strtoupper($document->currency)) !== 1) {
            $this->projector->recordGap([
                'organization_id' => $organizationId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_version' => $sourceVersion,
                'monetary_basis' => 'cash',
                'business_effective_at' => $recognizedAt ?? $document->created_at,
            ], array_values(array_filter([
                $recognizedAt === null ? 'recognized_on' : null,
                ! is_string($document->currency) ? 'currency' : null,
            ])));

            return;
        }
        try {
            $hierarchy = $this->hierarchies->resolve($organizationId);
        } catch (\InvalidArgumentException) {
            $this->projector->recordGap([
                'organization_id' => $organizationId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_version' => $sourceVersion,
                'monetary_basis' => 'cash',
                'business_effective_at' => $recognizedAt,
            ], ['hierarchy']);

            return;
        }
        $source = [
            'organization_id' => $organizationId,
            'holding_id' => $hierarchy->holdingId,
            'hierarchy_version' => $hierarchy->version,
            'hierarchy_organization_ids' => $hierarchy->organizationIds,
            'contributor_organization_id' => $organizationId,
            'counterparty_organization_id' => $document->counterparty_organization_id ?? null,
            'project_id' => $projectId,
            'contract_id' => $contractId,
            'allocation_id' => (int) $allocation->getKey(),
            'linked_parent_allocation_id' => null,
            'linked_incoming_minor' => null,
            'linked_outgoing_minor' => null,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_version' => $sourceVersion,
            'monetary_basis' => 'cash',
            'allocated_amount_minor' => null,
            'allocated_percentage' => $percentage,
            'contract_amount_minor' => $paymentAmountMinor,
            'currency' => mb_strtoupper($document->currency),
            'currency_source' => 'payment_document',
            'tax_basis' => 'payment_amount',
            'recognized_on' => $recognizedAt->format('Y-m-d'),
            'business_effective_at' => $recognizedAt,
            'source_refs' => [[
                'type' => 'payment_transaction',
                'id' => $sourceId,
            ]],
        ];
        $this->projector->persist($this->projector->project($source), $source);
    }
}
