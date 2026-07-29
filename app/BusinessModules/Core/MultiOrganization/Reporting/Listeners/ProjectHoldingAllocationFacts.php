<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Listeners;

use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAllocationFactVersion;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingAllocationFactProjector;
use App\BusinessModules\Core\Payments\Events\PaymentDocumentPaid;
use App\Models\Contract;
use App\Models\ContractProjectAllocation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

final readonly class ProjectHoldingAllocationFacts implements ShouldQueueAfterCommit
{
    public function __construct(private HoldingAllocationFactProjector $projector)
    {
    }

    public function handle(PaymentDocumentPaid $event): void
    {
        $document = $event->document;
        $contractId = (int) ($document->contract_id ?? 0);
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
        if (!$contract instanceof Contract || !$allocation instanceof ContractProjectAllocation) {
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
        $fact = $this->projector->project([
            'organization_id' => $organizationId,
            'holding_id' => $organizationId,
            'contributor_organization_id' => $organizationId,
            'counterparty_organization_id' => $document->counterparty_organization_id ?? null,
            'project_id' => $projectId,
            'contract_id' => $contractId,
            'allocation_id' => (int) $allocation->getKey(),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_version' => $sourceId,
            'monetary_basis' => 'cash',
            'allocated_amount_minor' => null,
            'allocated_percentage' => $percentage,
            'contract_amount_minor' => $paymentAmountMinor,
            'currency' => $document->currency ?? null,
            'recognized_on' => $document->paid_at?->toDateString() ?? now()->toDateString(),
            'flow_class' => 'unclassified',
            'source_refs' => [[
                'type' => 'payment_transaction',
                'id' => $sourceId,
            ]],
        ]);

        HoldingAllocationFactVersion::query()->firstOrCreate(
            [
                'organization_id' => $fact->organizationId,
                'source_type' => $fact->sourceType,
                'source_id' => $fact->sourceId,
                'source_version' => $fact->sourceVersion,
                'monetary_basis' => $fact->monetaryBasis,
            ],
            [
                'holding_id' => $fact->holdingId,
                'hierarchy_version' => 'unresolved',
                'contributor_organization_id' => $fact->contributorOrganizationId,
                'counterparty_organization_id' => $fact->counterpartyOrganizationId,
                'project_id' => $fact->projectId,
                'contract_id' => $fact->contractId,
                'linked_parent_allocation_id' => null,
                'tax_basis' => 'source',
                'amount_minor' => $fact->amountMinor,
                'currency' => $fact->currency,
                'currency_source' => $fact->currencySource,
                'recognized_on' => $fact->recognizedOn,
                'flow_class' => $fact->flowClass,
                'allocated_amount_minor' => null,
                'allocated_percentage' => $percentage,
                'contract_amount_minor' => $paymentAmountMinor,
                'source_refs' => $fact->sourceRefs,
                'source_hash' => hash('sha256', json_encode($fact, JSON_THROW_ON_ERROR)),
                'projected_at' => now(),
            ],
        );
    }
}
