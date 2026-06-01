<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentService;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\Enums\ContractorType;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

use function trans_message;

final class PurchaseOrderPaymentDocumentService
{
    public function __construct(
        private readonly PaymentDocumentService $paymentDocumentService,
        private readonly PurchaseOrderPaymentGateService $paymentGateService
    ) {
    }

    /**
     * @return array{document: PaymentDocument, created: bool}
     */
    public function createOrOpen(PurchaseOrder $order, ?int $createdByUserId = null): array
    {
        $order->loadMissing([
            'contract',
            'purchaseRequest.siteRequest',
            'supplier',
            'externalSupplierContact',
            'supplierParty',
        ]);

        $existingDocument = $this->paymentGateService->linkedDocuments($order)->first();

        if ($existingDocument instanceof PaymentDocument) {
            return [
                'document' => $existingDocument,
                'created' => false,
            ];
        }

        return DB::transaction(function () use ($order, $createdByUserId): array {
            $existingDocument = $this->paymentGateService->linkedDocuments($order)->first();

            if ($existingDocument instanceof PaymentDocument) {
                return [
                    'document' => $existingDocument,
                    'created' => false,
                ];
            }

            $contractor = $this->resolvePayeeContractor($order);
            $document = $this->paymentDocumentService->createPaymentOrder(
                $this->paymentDocumentPayload($order, $contractor, $createdByUserId)
            );

            return [
                'document' => $document,
                'created' => true,
            ];
        });
    }

    private function resolvePayeeContractor(PurchaseOrder $order): Contractor
    {
        $contract = $order->contract;

        if ($contract instanceof Contract && $contract->contractor_id !== null) {
            $contractor = Contractor::query()
                ->where('organization_id', $order->organization_id)
                ->find($contract->contractor_id);

            if ($contractor instanceof Contractor) {
                return $contractor;
            }
        }

        $supplier = $this->supplierPayload($order);

        $existing = $this->findExistingContractor(
            $order->organization_id,
            $supplier['name'],
            $supplier['inn'],
            $supplier['email']
        );

        if ($existing instanceof Contractor) {
            return $existing;
        }

        return Contractor::query()->create([
            'organization_id' => $order->organization_id,
            'name' => $supplier['name'],
            'contact_person' => $supplier['contact_person'],
            'phone' => $supplier['phone'],
            'email' => $supplier['email'],
            'legal_address' => $supplier['legal_address'],
            'inn' => $supplier['inn'],
            'contractor_type' => ContractorType::MANUAL,
        ]);
    }

    private function findExistingContractor(
        int $organizationId,
        string $name,
        ?string $inn,
        ?string $email
    ): ?Contractor {
        $query = Contractor::query()
            ->where('organization_id', $organizationId);

        if ($inn !== null) {
            return (clone $query)->where('inn', $inn)->first();
        }

        if ($email !== null) {
            return (clone $query)->where('email', $email)->first();
        }

        return $query->where('name', $name)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentDocumentPayload(PurchaseOrder $order, Contractor $contractor, ?int $createdByUserId): array
    {
        $contract = $order->contract;
        $payload = [
            'organization_id' => $order->organization_id,
            'project_id' => $this->projectId($order),
            'document_date' => now()->toDateString(),
            'direction' => InvoiceDirection::OUTGOING->value,
            'invoice_type' => InvoiceType::MATERIAL_PURCHASE->value,
            'payer_organization_id' => $order->organization_id,
            'payee_contractor_id' => $contractor->id,
            'contractor_id' => $contractor->id,
            'amount' => round((float) $order->total_amount, 2),
            'currency' => $order->currency ?: 'RUB',
            'due_date' => $order->delivery_date?->toDateString() ?? now()->addDays(7)->toDateString(),
            'description' => trans_message('procurement.chain.payment_document.description_prefix').' '.$order->order_number,
            'payment_purpose' => trans_message('procurement.chain.payment_document.payment_purpose_prefix').' '.$order->order_number,
            'metadata' => [
                'purchase_order_id' => $order->id,
                'purchase_order_number' => $order->order_number,
                'created_from' => 'procurement_chain',
            ],
            'created_by_user_id' => $createdByUserId,
        ];

        if ($contract instanceof Contract) {
            $payload['source_type'] = Contract::class;
            $payload['source_id'] = $contract->id;
            $payload['invoiceable_type'] = Contract::class;
            $payload['invoiceable_id'] = $contract->id;
        }

        return $payload;
    }

    private function projectId(PurchaseOrder $order): ?int
    {
        $siteRequest = $order->purchaseRequest?->siteRequest;

        if ($siteRequest?->project_id !== null) {
            return (int) $siteRequest->project_id;
        }

        return $order->contract?->project_id !== null ? (int) $order->contract->project_id : null;
    }

    /**
     * @return array{name: string, inn: ?string, email: ?string, phone: ?string, contact_person: ?string, legal_address: ?string}
     */
    private function supplierPayload(PurchaseOrder $order): array
    {
        $snapshot = is_array($order->supplier_snapshot) ? $order->supplier_snapshot : [];
        $supplierParty = $order->supplierParty;
        $externalSupplier = $order->externalSupplierContact;
        $supplier = $order->supplier;

        $name = $this->firstFilledString(
            $snapshot['display_name'] ?? null,
            $snapshot['name'] ?? null,
            $supplierParty?->display_name,
            $externalSupplier?->name,
            $supplier instanceof Supplier ? $supplier->name : null
        );

        if ($name === null) {
            throw new \DomainException(trans_message('procurement.chain.payment_document.supplier_required'));
        }

        return [
            'name' => $name,
            'inn' => $this->firstFilledString(
                $snapshot['tax_id'] ?? null,
                $snapshot['inn'] ?? null,
                $supplierParty?->tax_id,
                $externalSupplier?->tax_number,
                $supplier instanceof Supplier ? $supplier->inn : null,
                $supplier instanceof Supplier ? $supplier->tax_number : null
            ),
            'email' => $this->firstFilledString(
                $snapshot['email'] ?? null,
                $supplierParty?->email,
                $externalSupplier?->email,
                $supplier instanceof Supplier ? $supplier->email : null
            ),
            'phone' => $this->firstFilledString(
                $snapshot['phone'] ?? null,
                $supplierParty?->phone,
                $externalSupplier?->phone,
                $supplier instanceof Supplier ? $supplier->phone : null
            ),
            'contact_person' => $this->firstFilledString(
                $snapshot['contact_person'] ?? null,
                $supplierParty?->contact_name,
                $externalSupplier?->contact_person,
                $supplier instanceof Supplier ? $supplier->contact_person : null
            ),
            'legal_address' => $this->firstFilledString(
                $snapshot['legal_address'] ?? null,
                $snapshot['address'] ?? null,
                $externalSupplier?->address,
                $supplier instanceof Supplier ? $supplier->address : null
            ),
        ];
    }

    private function firstFilledString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }

            $normalized = trim((string) $value);

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }
}
