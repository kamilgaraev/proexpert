<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Enums\ProcurementAuditEventTypeEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierProposalDecisionEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierProposalStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierProposalVatModeEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

use function trans_message;

class SupplierProposalService
{
    public function __construct(
        private readonly ProcurementAuditService $auditService,
        private readonly SupplierProposalIntakeService $intakeService,
        private readonly SupplierProposalVersionService $versionService,
        private readonly SupplierRequestVersionService $requestVersionService,
        private readonly ProcurementLifecycleService $lifecycleService,
        private readonly SupplierPartyService $supplierPartyService
    ) {}

    public function createFromSupplierRequest(
        SupplierRequest $supplierRequest,
        array $data,
        ?int $actorId = null
    ): SupplierProposal {
        $stage = 'start';

        try {
            return DB::transaction(function () use ($supplierRequest, $data, $actorId, &$stage): SupplierProposal {
                $stage = 'sync_expiry';
                $supplierRequest = $this->lifecycleService->syncSupplierRequestExpiry($supplierRequest);

                if (! $supplierRequest->canReceivePublicProposal()) {
                    throw ValidationException::withMessages([
                        'supplier_request_id' => trans_message('procurement.supplier_requests.cannot_receive_proposal'),
                    ]);
                }

                $stage = 'resolve_request_version';
                $supplierRequest->loadMissing(['lines', 'supplierParty']);
                $supplierRequestVersion = $this->requestVersionService->resolveForProposal($supplierRequest, $actorId);
                $amounts = $this->commercialAmounts($data);

                $stage = 'create_proposal';
                $proposal = SupplierProposal::query()->create([
                    'organization_id' => $supplierRequest->organization_id,
                    'supplier_request_id' => $supplierRequest->id,
                    'supplier_request_version_id' => $supplierRequestVersion->id,
                    'supplier_id' => $supplierRequest->supplier_id,
                    'external_supplier_contact_id' => $supplierRequest->external_supplier_contact_id,
                    'supplier_party_id' => $supplierRequest->supplier_party_id,
                    'supplier_snapshot' => $supplierRequest->supplier_snapshot ?? [],
                    'proposal_number' => $this->generateProposalNumber($supplierRequest->organization_id),
                    'proposal_date' => $data['proposal_date'] ?? now(),
                    'status' => SupplierProposalStatusEnum::SUBMITTED,
                    'subtotal_amount' => $amounts['subtotal_amount'],
                    'delivery_amount' => $amounts['delivery_amount'],
                    'vat_amount' => $amounts['vat_amount'],
                    'total_amount' => $amounts['total_amount'],
                    'currency' => $data['currency'] ?? 'RUB',
                    'vat_mode' => $data['vat_mode'] ?? SupplierProposalVatModeEnum::INCLUDED->value,
                    'vat_rate' => $data['vat_rate'] ?? null,
                    'valid_until' => $data['valid_until'] ?? null,
                    'delivery_due_date' => $data['delivery_due_date'] ?? null,
                    'lead_time_days' => $data['lead_time_days'] ?? null,
                    'payment_terms' => $data['payment_terms'] ?? null,
                    'delivery_terms' => $data['delivery_terms'] ?? null,
                    'warranty_terms' => $data['warranty_terms'] ?? null,
                    'items' => $data['items'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'metadata' => $data['metadata'] ?? null,
                ]);

                foreach ($data['items'] ?? [] as $item) {
                    $quantity = (float) $item['quantity'];
                    $unitPrice = (float) $item['unit_price'];

                    $proposal->lines()->create([
                        'supplier_request_line_id' => $item['supplier_request_line_id'] ?? null,
                        'material_id' => $this->resolveLineMaterialId($supplierRequest, $item['supplier_request_line_id'] ?? null),
                        'name' => $item['name'],
                        'quantity' => $quantity,
                        'unit' => $item['unit'],
                        'unit_price' => $unitPrice,
                        'total_amount' => $item['total_amount'] ?? round($quantity * $unitPrice, 2),
                        'comment' => $item['comment'] ?? null,
                        'metadata' => $item['metadata'] ?? null,
                    ]);
                }

                $stage = 'record_intake';
                $proposal->load(['supplierParty', 'lines']);
                $this->intakeService->recordForProposal($proposal, $data, $actorId);
                $stage = 'create_version';
                $proposal->load('intake');
                $this->versionService->createInitialVersion($proposal, $actorId);

                $stage = 'mark_request_responded';
                $supplierRequest->update([
                    'status' => SupplierRequestStatusEnum::RESPONDED,
                    'responded_at' => now(),
                ]);

                $stage = 'mark_party_responded';
                $respondedParty = $this->supplierPartyService->markResponded($supplierRequest->supplier_party_id);

                if ($respondedParty !== null) {
                    $respondedSnapshot = $this->supplierPartyService->snapshotForDocument($respondedParty);
                    $supplierRequest->update(['supplier_snapshot' => $respondedSnapshot]);
                    $proposal->update(['supplier_snapshot' => $respondedSnapshot]);
                }

                $stage = 'dispatch_event';
                event(new \App\BusinessModules\Features\Procurement\Events\SupplierProposalReceived($proposal));

                $snapshot = is_array($proposal->supplier_snapshot) ? $proposal->supplier_snapshot : [];

                $stage = 'record_audit';
                $this->auditService->record(
                    ProcurementAuditEventTypeEnum::SUPPLIER_PROPOSAL_CREATED->value,
                    $proposal,
                    (int) $proposal->organization_id,
                    $actorId,
                    $proposal->supplier_party_id,
                    [
                        'proposal_number' => $proposal->proposal_number,
                        'status' => $proposal->status->value,
                        'supplier_request_number' => $supplierRequest->request_number,
                        'supplier_request_version_id' => $supplierRequestVersion->id,
                        'supplier_request_version_number' => $supplierRequestVersion->version_number,
                        'supplier_name' => $this->supplierName($proposal, $snapshot),
                        'supplier_snapshot' => $snapshot,
                        'total_amount' => (float) $proposal->total_amount,
                        'currency' => $proposal->currency,
                        'valid_until' => $proposal->valid_until?->format('Y-m-d'),
                        'lines_count' => count($data['items'] ?? []),
                    ]
                );

                return $proposal->fresh([
                    'supplier',
                    'externalSupplierContact',
                    'supplierParty',
                    'supplierRequest',
                    'supplierRequestVersion',
                    'lines',
                    'intake',
                    'currentVersion',
                ]);
            });
        } catch (Throwable $exception) {
            Log::error('procurement.supplier_proposals.create_from_request.error', [
                'stage' => $stage,
                'supplier_request_id' => $supplierRequest->id,
                'organization_id' => $supplierRequest->organization_id,
                'supplier_party_id' => $supplierRequest->supplier_party_id,
                'has_registered_supplier' => $supplierRequest->supplier_id !== null,
                'has_external_supplier' => $supplierRequest->external_supplier_contact_id !== null,
                'items_count' => count($data['items'] ?? []),
                'exception_class' => $exception::class,
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
            ]);

            throw $exception;
        }
    }

    public function accept(SupplierProposal $proposal, ?int $actorId = null): SupplierProposal
    {
        $acceptedProposal = DB::transaction(function () use ($proposal, $actorId): SupplierProposal {
            $lockedProposal = SupplierProposal::query()
                ->whereKey($proposal->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedProposal->canBeAccepted()) {
                throw new \DomainException(trans_message('procurement.proposals.accept_invalid_status'));
            }

            $this->lifecycleService->assertCanAcceptProposal($lockedProposal);

            if ($lockedProposal->supplier_request_id === null) {
                throw new \DomainException(trans_message('procurement.proposal_decisions.accepted_decision_required'));
            }

            SupplierRequest::query()
                ->whereKey($lockedProposal->supplier_request_id)
                ->lockForUpdate()
                ->firstOrFail();

            $decision = SupplierProposalDecision::query()
                ->where('organization_id', $lockedProposal->organization_id)
                ->where('supplier_request_id', $lockedProposal->supplier_request_id)
                ->lockForUpdate()
                ->first();

            if (
                $decision === null
                || $decision->winning_supplier_proposal_id !== $lockedProposal->id
            ) {
                throw new \DomainException(trans_message('procurement.proposal_decisions.accepted_decision_required'));
            }

            if ($decision->status === SupplierProposalDecisionEnum::APPROVAL_REQUIRED) {
                throw new \DomainException(trans_message('procurement.proposal_decisions.approval_required'));
            }

            if ($decision->status === SupplierProposalDecisionEnum::REJECTED) {
                throw new \DomainException(trans_message('procurement.proposal_decisions.rejected_decision_cannot_be_accepted'));
            }

            if (! in_array($decision->status, [
                SupplierProposalDecisionEnum::SELECTED,
                SupplierProposalDecisionEnum::APPROVED,
            ], true)) {
                throw new \DomainException(trans_message('procurement.proposal_decisions.accepted_decision_required'));
            }

            $acceptedVersion = $lockedProposal->currentVersion()->lockForUpdate()->first();

            if ($acceptedVersion === null) {
                throw ValidationException::withMessages([
                    'proposal_id' => [trans_message('procurement_enterprise.proposals.version_required')],
                ]);
            }

            $existingOrder = PurchaseOrder::query()
                ->where('organization_id', $lockedProposal->organization_id)
                ->where(function ($query) use ($lockedProposal): void {
                    $query->where('accepted_supplier_proposal_id', $lockedProposal->id)
                        ->orWhereHas('acceptedSupplierProposal', function ($proposalQuery) use ($lockedProposal): void {
                            $proposalQuery
                                ->where('organization_id', $lockedProposal->organization_id)
                                ->where('supplier_request_id', $lockedProposal->supplier_request_id);
                        });
                })
                ->lockForUpdate()
                ->first();

            if ($existingOrder !== null) {
                throw ValidationException::withMessages([
                    'proposal_id' => [trans_message('procurement.proposals.purchase_order_already_exists')],
                ]);
            }

            $lockedProposal->load(['supplierRequest.purchaseRequest', 'lines']);

            $lockedProposal->update([
                'status' => SupplierProposalStatusEnum::ACCEPTED,
            ]);

            $acceptedSnapshot = is_array($acceptedVersion->commercial_snapshot)
                ? $acceptedVersion->commercial_snapshot
                : [];

            $order = PurchaseOrder::query()->create([
                'organization_id' => $lockedProposal->organization_id,
                'purchase_request_id' => $lockedProposal->supplierRequest?->purchase_request_id,
                'accepted_supplier_proposal_id' => $lockedProposal->id,
                'accepted_supplier_proposal_version_id' => $acceptedVersion->id,
                'supplier_id' => $lockedProposal->supplier_id,
                'external_supplier_contact_id' => $lockedProposal->external_supplier_contact_id,
                'supplier_party_id' => $lockedProposal->supplier_party_id,
                'supplier_snapshot' => $lockedProposal->supplier_snapshot ?? [],
                'order_number' => $this->generateOrderNumber($lockedProposal->organization_id),
                'order_date' => now(),
                'status' => PurchaseOrderStatusEnum::CONFIRMED,
                'total_amount' => $this->snapshotFloat($acceptedSnapshot, 'total_amount', (float) $lockedProposal->total_amount),
                'currency' => (string) ($acceptedSnapshot['currency'] ?? $lockedProposal->currency),
                'pricing_source' => 'accepted_supplier_proposal',
                'delivery_date' => $acceptedSnapshot['delivery_due_date'] ?? $lockedProposal->supplierRequest?->purchaseRequest?->needed_by,
                'confirmed_at' => now(),
                'notes' => $lockedProposal->notes,
                'metadata' => [
                    'accepted_supplier_proposal_id' => $lockedProposal->id,
                    'accepted_supplier_proposal_version_id' => $acceptedVersion->id,
                    'supplier_request_id' => $lockedProposal->supplier_request_id,
                    'commercial_snapshot' => $acceptedVersion->commercial_snapshot,
                ],
            ]);

            foreach ($this->orderLinesFromVersion($acceptedVersion->commercial_snapshot, $lockedProposal) as $line) {
                $order->items()->create([
                    'material_id' => $line['material_id'],
                    'material_name' => $line['name'],
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'],
                    'unit_price' => $line['unit_price'],
                    'total_price' => $line['total_amount'],
                    'notes' => $line['comment'],
                    'metadata' => [
                        'supplier_proposal_line_id' => $line['supplier_proposal_line_id'],
                        'supplier_request_line_id' => $line['supplier_request_line_id'],
                        'supplier_proposal_version_id' => $acceptedVersion->id,
                    ],
                ]);
            }

            $lockedProposal->update([
                'purchase_order_id' => $order->id,
            ]);

            $selectedParty = $this->supplierPartyService->markSelected($lockedProposal->supplier_party_id);

            if ($selectedParty !== null) {
                $selectedSnapshot = $this->supplierPartyService->snapshotForDocument($selectedParty);
                $lockedProposal->update(['supplier_snapshot' => $selectedSnapshot]);
                $order->update(['supplier_snapshot' => $selectedSnapshot]);
            }

            $snapshot = is_array($lockedProposal->supplier_snapshot) ? $lockedProposal->supplier_snapshot : [];

            $this->auditService->record(
                ProcurementAuditEventTypeEnum::PURCHASE_ORDER_CREATED->value,
                $order,
                (int) $order->organization_id,
                $actorId,
                $order->supplier_party_id,
                [
                    'order_number' => $order->order_number,
                    'status' => $order->status->value,
                    'accepted_supplier_proposal_number' => $lockedProposal->proposal_number,
                    'supplier_request_number' => $lockedProposal->supplierRequest?->request_number,
                    'supplier_name' => $this->supplierName($lockedProposal, $snapshot),
                    'supplier_snapshot' => $snapshot,
                    'total_amount' => (float) $order->total_amount,
                    'currency' => $order->currency,
                    'items_count' => $lockedProposal->lines->count(),
                    'pricing_source' => $order->pricing_source,
                ]
            );

            return $lockedProposal;
        });

        return $acceptedProposal->fresh([
            'supplier',
            'externalSupplierContact',
            'supplierParty',
            'supplierRequest',
            'supplierRequestVersion',
            'purchaseOrder.supplierParty',
            'purchaseOrder.acceptedSupplierProposalVersion',
            'lines',
            'intake',
            'currentVersion',
        ]);
    }

    public function reject(SupplierProposal $proposal, string $reason): SupplierProposal
    {
        if ($proposal->status->isFinal()) {
            throw new \DomainException(trans_message('procurement.proposals.reject_invalid_status'));
        }

        $proposal->update([
            'status' => SupplierProposalStatusEnum::REJECTED,
            'notes' => ($proposal->notes ? $proposal->notes."\n\n" : '')."Отклонено: {$reason}",
        ]);

        return $proposal->fresh(['supplier', 'externalSupplierContact', 'supplierParty', 'supplierRequest', 'supplierRequestVersion', 'lines']);
    }

    private function resolveLineMaterialId(SupplierRequest $supplierRequest, ?int $supplierRequestLineId): ?int
    {
        if ($supplierRequestLineId === null) {
            return null;
        }

        return $supplierRequest->lines
            ->firstWhere('id', $supplierRequestLineId)
            ?->material_id;
    }

    private function generateProposalNumber(int $organizationId): string
    {
        $prefix = 'КП-'.now()->format('Ym');
        $lastNumber = SupplierProposal::query()
            ->where('organization_id', $organizationId)
            ->where('proposal_number', 'like', $prefix.'-%')
            ->count() + 1;

        return sprintf('%s-%04d', $prefix, $lastNumber);
    }

    private function generateOrderNumber(int $organizationId): string
    {
        $prefix = 'ЗП-'.now()->format('Ym');
        $lastNumber = PurchaseOrder::query()
            ->where('organization_id', $organizationId)
            ->where('order_number', 'like', $prefix.'-%')
            ->count() + 1;

        return sprintf('%s-%04d', $prefix, $lastNumber);
    }

    /**
     * @return array{subtotal_amount: float, delivery_amount: float, vat_amount: float, total_amount: float}
     */
    private function commercialAmounts(array $data): array
    {
        $deliveryAmount = round((float) ($data['delivery_amount'] ?? 0), 2);
        $lineSubtotal = $this->itemsSubtotal($data['items'] ?? []);
        $totalAmount = round((float) $data['total_amount'], 2);
        $subtotalAmount = array_key_exists('subtotal_amount', $data)
            ? round((float) $data['subtotal_amount'], 2)
            : ($lineSubtotal > 0.0 ? $lineSubtotal : max(0.0, round($totalAmount - $deliveryAmount, 2)));
        $vatAmount = $this->vatAmount($data, $subtotalAmount);
        $calculatedTotal = round($subtotalAmount + $deliveryAmount + $vatAmount, 2);
        $totalAmount = abs($totalAmount - $calculatedTotal) > 0.01 ? $calculatedTotal : $totalAmount;

        return [
            'subtotal_amount' => $subtotalAmount,
            'delivery_amount' => $deliveryAmount,
            'vat_amount' => $vatAmount,
            'total_amount' => $totalAmount,
        ];
    }

    private function itemsSubtotal(array $items): float
    {
        $sum = 0.0;

        foreach ($items as $item) {
            $quantity = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? $item['price'] ?? 0);
            $sum += (float) ($item['total_amount'] ?? round($quantity * $unitPrice, 2));
        }

        return round($sum, 2);
    }

    private function vatAmount(array $data, float $subtotalAmount): float
    {
        if (array_key_exists('vat_amount', $data)) {
            return round((float) $data['vat_amount'], 2);
        }

        if (($data['vat_mode'] ?? null) !== SupplierProposalVatModeEnum::EXCLUDED->value) {
            return 0.0;
        }

        $vatRate = (float) ($data['vat_rate'] ?? 0);

        return round($subtotalAmount * $vatRate / 100, 2);
    }

    private function snapshotFloat(array $snapshot, string $key, float $fallback): float
    {
        if (! array_key_exists($key, $snapshot)) {
            return $fallback;
        }

        return round((float) $snapshot[$key], 2);
    }

    private function orderLinesFromVersion(?array $commercialSnapshot, SupplierProposal $proposal): array
    {
        $snapshotLines = is_array($commercialSnapshot) && is_array($commercialSnapshot['lines'] ?? null)
            ? $commercialSnapshot['lines']
            : [];

        if ($snapshotLines !== []) {
            return collect($snapshotLines)
                ->map(static fn (array $line): array => [
                    'supplier_proposal_line_id' => $line['id'] ?? null,
                    'supplier_request_line_id' => $line['supplier_request_line_id'] ?? null,
                    'material_id' => $line['material_id'] ?? null,
                    'name' => (string) ($line['name'] ?? ''),
                    'quantity' => (float) ($line['quantity'] ?? 0),
                    'unit' => (string) ($line['unit'] ?? ''),
                    'unit_price' => (float) ($line['unit_price'] ?? 0),
                    'total_amount' => (float) ($line['total_amount'] ?? 0),
                    'comment' => $line['comment'] ?? null,
                ])
                ->values()
                ->all();
        }

        return $proposal->lines
            ->map(static fn ($line): array => [
                'supplier_proposal_line_id' => $line->id,
                'supplier_request_line_id' => $line->supplier_request_line_id,
                'material_id' => $line->material_id,
                'name' => $line->name,
                'quantity' => (float) $line->quantity,
                'unit' => $line->unit,
                'unit_price' => (float) $line->unit_price,
                'total_amount' => (float) $line->total_amount,
                'comment' => $line->comment,
            ])
            ->values()
            ->all();
    }

    private function supplierName(SupplierProposal $proposal, array $snapshot): ?string
    {
        if (($snapshot['display_name'] ?? null) !== null) {
            return (string) $snapshot['display_name'];
        }

        if ($proposal->relationLoaded('supplier') && $proposal->supplier !== null) {
            return $proposal->supplier->name;
        }

        if ($proposal->relationLoaded('externalSupplierContact') && $proposal->externalSupplierContact !== null) {
            return $proposal->externalSupplierContact->name;
        }

        if ($proposal->relationLoaded('supplierParty') && $proposal->supplierParty !== null) {
            return $proposal->supplierParty->display_name;
        }

        return null;
    }
}
