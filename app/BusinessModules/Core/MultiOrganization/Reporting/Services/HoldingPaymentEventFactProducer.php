<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Services;

use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingAllocationFact;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAllocationFactVersion;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingPaymentTransactionEventVersion;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\Enums\CurrencyCode;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final readonly class HoldingPaymentEventFactProducer
{
    public function __construct(
        private HoldingAllocationFactProjector $projector,
        private HoldingHierarchyResolver $hierarchies,
        private HoldingContractDimensionResolver $contractDimensions,
        private HoldingAllocationContextResolver $allocationContexts,
    ) {}

    public function canProject(HoldingPaymentTransactionEventVersion $event, ?int $holdingId = null): bool
    {
        $projection = $this->projection($event);

        return $projection['missing'] === []
            && (! $event->active
                || $holdingId === null
                || ($projection['identity']['holding_id'] ?? null) === $holdingId);
    }

    public function project(HoldingPaymentTransactionEventVersion $event): ?HoldingAllocationFactVersion
    {
        $projection = $this->projection($event);
        if ($projection['missing'] !== []) {
            $this->projector->recordGap($projection['identity'], $projection['missing']);

            return null;
        }
        $source = $projection['source'];
        if (! is_array($source)) {
            return null;
        }

        return $this->projector->persist($this->projector->project($source), $source);
    }

    public function previewEvent(HoldingPaymentTransactionEventVersion $event): ?HoldingAllocationFact
    {
        $projection = $this->projection($event);
        $source = $projection['source'];

        return $projection['missing'] === [] && is_array($source)
            ? $this->projector->project($source)
            : null;
    }

    private function projection(HoldingPaymentTransactionEventVersion $event): array
    {
        $organizationId = (int) $event->organization_id;
        $projectId = (int) ($event->project_id ?? 0);
        $contractId = (int) ($event->contract_id ?? 0);
        $sourceId = (int) $event->transaction_id;
        $sourceVersion = (int) $event->getKey();
        $recognizedAt = $event->recognized_at;
        $identity = [
            'organization_id' => $organizationId,
            'source_type' => 'payment_transaction_event',
            'source_id' => $sourceId,
            'source_version' => $sourceVersion,
            'monetary_basis' => 'cash',
            'business_effective_at' => $recognizedAt ?? $event->occurred_at,
        ];
        $missing = [];
        if (min($organizationId, $projectId, $contractId, $sourceId, $sourceVersion) < 1) {
            $missing[] = 'payment_event_identity';
        }
        if (! $event->history_complete) {
            $missing[] = 'payment_event_history';
        }
        if ($recognizedAt === null) {
            $missing[] = 'recognized_on';
        }
        if ((int) ($event->document_organization_id ?? 0) !== $organizationId
            || (int) ($event->contract_organization_id ?? 0) !== $organizationId
            || (int) ($event->document_project_id ?? 0) !== $projectId
            || (int) ($event->contract_project_id ?? 0) !== $projectId) {
            $missing[] = 'payment_event_scope';
        }
        if (preg_match('/^[a-f0-9]{64}$/D', (string) $event->source_hash) !== 1) {
            $missing[] = 'payment_event_hash';
        }
        if ($missing !== []) {
            return ['identity' => $identity, 'missing' => array_values(array_unique($missing)), 'source' => null];
        }
        if (! $event->active) {
            return ['identity' => $identity, 'missing' => [], 'source' => null];
        }

        $rawCurrency = is_string($event->currency) ? mb_strtoupper(trim($event->currency)) : '';
        if (preg_match('/^[A-Z]{3}$/D', $rawCurrency) !== 1
            || CurrencyCode::tryFrom($rawCurrency) === null) {
            $missing[] = 'currency';
        }
        if ($event->amount === null || ! is_numeric($event->amount)) {
            $missing[] = 'amount';
        }
        $status = $event->status instanceof PaymentTransactionStatus
            ? $event->status
            : PaymentTransactionStatus::tryFrom((string) $event->status);
        if (! in_array($status, [PaymentTransactionStatus::COMPLETED, PaymentTransactionStatus::REFUNDED], true)) {
            $missing[] = 'payment_status';
        }
        if ($missing !== []) {
            return ['identity' => $identity, 'missing' => array_values(array_unique($missing)), 'source' => null];
        }

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
        if ($dimension !== null && $rawCurrency !== $dimension->rawCurrency) {
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
        $identity = [
            ...$identity,
            'holding_id' => $hierarchy?->holdingId,
            'hierarchy_version' => $hierarchy?->version,
        ];
        if ($missing !== [] || $hierarchy === null || $dimension === null || $allocation === null) {
            return ['identity' => $identity, 'missing' => array_values(array_unique($missing)), 'source' => null];
        }

        try {
            $amountMinor = $this->moneyToMinor((string) $event->amount);
        } catch (InvalidArgumentException) {
            return ['identity' => $identity, 'missing' => ['amount'], 'source' => null];
        }
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
            'source_type' => 'payment_transaction_event',
            'source_id' => $sourceId,
            'source_version' => $sourceVersion,
            'monetary_basis' => 'cash',
            'allocated_amount_minor' => null,
            'allocated_percentage' => $allocation->allocatedPercentage,
            'contract_amount_minor' => $amountMinor,
            'currency' => $dimension->currency,
            'currency_source' => $dimension->currency === null
                ? 'unknown_payment_event_contract_dimension'
                : 'payment_event_contract_dimension',
            'tax_basis' => 'payment_amount',
            'recognized_on' => $recognizedAt->format('Y-m-d'),
            'business_effective_at' => $recognizedAt,
            'source_refs' => [[
                'type' => 'payment_transaction',
                'id' => $sourceId,
                'version' => $sourceVersion,
                'hash' => (string) $event->source_hash,
            ], [
                'type' => 'allocation_context',
                'id' => $allocation->eventId,
                'hash' => $allocation->evidenceHash,
            ], [
                'type' => 'contract_dimension',
                'id' => $dimension->eventId,
                'hash' => $dimension->evidenceHash,
                'currency_code' => $dimension->rawCurrency,
            ], [
                'type' => 'organization_hierarchy',
                'hash' => $hierarchy->version,
                'evidence_hashes' => $hierarchy->evidenceHashes,
            ]],
        ];
        $missing = $this->projector->missingEvidence($source);

        return ['identity' => $identity, 'missing' => $missing, 'source' => $missing === [] ? $source : null];
    }

    private function moneyToMinor(string $amount): int
    {
        try {
            return BigDecimal::of($amount)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
        } catch (MathException) {
            throw new InvalidArgumentException('holding_payment_amount_invalid');
        }
    }
}
