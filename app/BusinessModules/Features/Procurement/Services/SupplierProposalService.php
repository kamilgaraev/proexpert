<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierProposalStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use Illuminate\Support\Facades\DB;
use function trans_message;

class SupplierProposalService
{
    public function createFromSupplierRequest(SupplierRequest $supplierRequest, array $data): SupplierProposal
    {
        return DB::transaction(function () use ($supplierRequest, $data): SupplierProposal {
            $supplierRequest->loadMissing('lines');

            $proposal = SupplierProposal::query()->create([
                'organization_id' => $supplierRequest->organization_id,
                'supplier_request_id' => $supplierRequest->id,
                'supplier_id' => $supplierRequest->supplier_id,
                'external_supplier_contact_id' => $supplierRequest->external_supplier_contact_id,
                'proposal_number' => $this->generateProposalNumber($supplierRequest->organization_id),
                'proposal_date' => $data['proposal_date'] ?? now(),
                'status' => SupplierProposalStatusEnum::SUBMITTED,
                'subtotal_amount' => $data['subtotal_amount'] ?? $data['total_amount'],
                'delivery_amount' => $data['delivery_amount'] ?? 0,
                'vat_amount' => $data['vat_amount'] ?? 0,
                'total_amount' => $data['total_amount'],
                'currency' => $data['currency'] ?? 'RUB',
                'valid_until' => $data['valid_until'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'delivery_terms' => $data['delivery_terms'] ?? null,
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

            $supplierRequest->update([
                'status' => SupplierRequestStatusEnum::RESPONDED,
                'responded_at' => now(),
            ]);

            event(new \App\BusinessModules\Features\Procurement\Events\SupplierProposalReceived($proposal));

            return $proposal->fresh(['supplier', 'externalSupplierContact', 'supplierRequest', 'lines']);
        });
    }

    public function accept(SupplierProposal $proposal): SupplierProposal
    {
        if (!$proposal->canBeAccepted()) {
            throw new \DomainException(trans_message('procurement.proposals.accept_invalid_status'));
        }

        DB::transaction(function () use ($proposal): void {
            $proposal->loadMissing(['supplierRequest.purchaseRequest', 'lines']);

            $proposal->update([
                'status' => SupplierProposalStatusEnum::ACCEPTED,
            ]);

            $order = PurchaseOrder::query()->create([
                'organization_id' => $proposal->organization_id,
                'purchase_request_id' => $proposal->supplierRequest?->purchase_request_id,
                'accepted_supplier_proposal_id' => $proposal->id,
                'supplier_id' => $proposal->supplier_id,
                'external_supplier_contact_id' => $proposal->external_supplier_contact_id,
                'order_number' => $this->generateOrderNumber($proposal->organization_id),
                'order_date' => now(),
                'status' => PurchaseOrderStatusEnum::CONFIRMED,
                'total_amount' => $proposal->total_amount,
                'currency' => $proposal->currency,
                'pricing_source' => 'accepted_supplier_proposal',
                'delivery_date' => $proposal->supplierRequest?->purchaseRequest?->needed_by,
                'confirmed_at' => now(),
                'notes' => $proposal->notes,
                'metadata' => [
                    'accepted_supplier_proposal_id' => $proposal->id,
                    'supplier_request_id' => $proposal->supplier_request_id,
                ],
            ]);

            foreach ($proposal->lines as $line) {
                $order->items()->create([
                    'material_id' => $line->material_id,
                    'material_name' => $line->name,
                    'quantity' => $line->quantity,
                    'unit' => $line->unit,
                    'unit_price' => $line->unit_price,
                    'total_price' => $line->total_amount,
                    'notes' => $line->comment,
                    'metadata' => [
                        'supplier_proposal_line_id' => $line->id,
                        'supplier_request_line_id' => $line->supplier_request_line_id,
                    ],
                ]);
            }

            $proposal->update([
                'purchase_order_id' => $order->id,
            ]);
        });

        return $proposal->fresh(['supplier', 'externalSupplierContact', 'supplierRequest', 'purchaseOrder', 'lines']);
    }

    public function reject(SupplierProposal $proposal, string $reason): SupplierProposal
    {
        if ($proposal->status->isFinal()) {
            throw new \DomainException(trans_message('procurement.proposals.reject_invalid_status'));
        }

        $proposal->update([
            'status' => SupplierProposalStatusEnum::REJECTED,
            'notes' => ($proposal->notes ? $proposal->notes . "\n\n" : '') . "Отклонено: {$reason}",
        ]);

        return $proposal->fresh(['supplier', 'externalSupplierContact', 'supplierRequest', 'lines']);
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
        $prefix = 'КП-' . now()->format('Ym');
        $lastNumber = SupplierProposal::query()
            ->where('organization_id', $organizationId)
            ->where('proposal_number', 'like', $prefix . '-%')
            ->count() + 1;

        return sprintf('%s-%04d', $prefix, $lastNumber);
    }

    private function generateOrderNumber(int $organizationId): string
    {
        $prefix = 'ЗП-' . now()->format('Ym');
        $lastNumber = PurchaseOrder::query()
            ->where('organization_id', $organizationId)
            ->where('order_number', 'like', $prefix . '-%')
            ->count() + 1;

        return sprintf('%s-%04d', $prefix, $lastNumber);
    }
}
