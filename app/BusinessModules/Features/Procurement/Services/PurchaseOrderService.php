<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Services\ProjectMaterialDeliveryService;
use App\BusinessModules\Features\Procurement\Enums\ProcurementAuditEventTypeEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceipt;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\Models\Contract;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function trans_message;

class PurchaseOrderService
{
    public function __construct(
        private readonly PurchaseOrderPdfService $pdfService,
        private readonly SupplierPartyService $supplierPartyService,
        private readonly ProcurementAuditService $auditService,
        private readonly ProcurementLifecycleService $lifecycleService,
        private readonly PurchaseOrderPaymentGateService $paymentGateService,
        private readonly ProjectMaterialDeliveryService $deliveryService
    ) {}

    public function create(PurchaseRequest $request, int $supplierId, array $data, ?int $actorId = null): PurchaseOrder
    {
        $this->lifecycleService->assertCanCreateSupplierRequest($request);

        if ($request->purchaseOrders()->exists()) {
            throw new \DomainException(trans_message('procurement.purchase_orders.already_exists_for_request'));
        }

        $supplier = Supplier::query()
            ->where('organization_id', $request->organization_id)
            ->where('id', $supplierId)
            ->where('is_active', true)
            ->first();

        if (! $supplier) {
            throw new \DomainException(trans_message('procurement.purchase_orders.supplier_not_found'));
        }

        $supplierParty = $this->supplierPartyService->resolveRegisteredParty($request->organization_id, $supplierId);
        $supplierSnapshot = $this->supplierPartyService->snapshotForDocument($supplierParty);

        DB::beginTransaction();

        try {
            $orderNumber = $this->generateOrderNumber();

            $order = PurchaseOrder::create([
                'organization_id' => $request->organization_id,
                'purchase_request_id' => $request->id,
                'supplier_id' => $supplierId,
                'supplier_party_id' => $supplierParty->id,
                'supplier_snapshot' => $supplierSnapshot,
                'order_number' => $orderNumber,
                'order_date' => $data['order_date'] ?? now(),
                'status' => PurchaseOrderStatusEnum::DRAFT,
                'total_amount' => $data['total_amount'] ?? 0,
                'currency' => $data['currency'] ?? 'RUB',
                'delivery_date' => $data['delivery_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            $siteRequest = $request->siteRequest;
            if ($siteRequest && ($siteRequest->material_id || $siteRequest->material_name)) {
                $order->items()->create([
                    'material_id' => $siteRequest->material_id,
                    'material_name' => $siteRequest->material_name,
                    'quantity' => $siteRequest->material_quantity ?? 1,
                    'unit' => $siteRequest->material_unit ?? 'шт.',
                    'unit_price' => 0,
                    'total_price' => 0,
                ]);
            }

            $this->auditService->record(
                ProcurementAuditEventTypeEnum::PURCHASE_ORDER_CREATED->value,
                $order,
                (int) $order->organization_id,
                $actorId,
                $order->supplier_party_id,
                [
                    'order_number' => $order->order_number,
                    'status' => $order->status->value,
                    'purchase_request_number' => $request->request_number,
                    'supplier_name' => $this->supplierName($supplierSnapshot),
                    'supplier_snapshot' => $supplierSnapshot,
                    'total_amount' => (float) $order->total_amount,
                    'currency' => $order->currency,
                    'pricing_source' => $order->pricing_source,
                ]
            );

            $this->syncDeliveryFromOrder($order, $request, $actorId);

            DB::commit();

            $this->invalidateCache($request->organization_id);

            event(new \App\BusinessModules\Features\Procurement\Events\PurchaseOrderCreated($order));

            return $order->fresh(['supplier', 'supplierParty', 'purchaseRequest', 'items']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function sendToSupplier(PurchaseOrder $order): PurchaseOrder
    {
        if (! $order->canBeSent()) {
            throw new \DomainException(trans_message('procurement.purchase_orders.invalid_status_for_send'));
        }

        if (! $order->supplier || ! $order->supplier->email) {
            throw new \DomainException(trans_message('procurement.purchase_orders.supplier_email_missing'));
        }

        DB::beginTransaction();

        try {
            $pdfPath = $this->pdfService->store($order);
            $temporaryUrl = $this->pdfService->getTemporaryUrl($order, $pdfPath, 1440);

            \Illuminate\Support\Facades\Mail::to($order->supplier->email)
                ->queue(new \App\BusinessModules\Features\Procurement\Mail\PurchaseOrderSentMail($order, $temporaryUrl));

            $order->update([
                'status' => PurchaseOrderStatusEnum::SENT,
                'sent_at' => now(),
                'metadata' => array_merge($order->metadata ?? [], [
                    'pdf_path' => $pdfPath,
                    'pdf_temporary_url' => $temporaryUrl,
                    'email_sent_to' => $order->supplier->email,
                    'sent_by_user_id' => auth()->id(),
                ]),
            ]);

            DB::commit();

            $this->invalidateCache($order->organization_id);

            event(new \App\BusinessModules\Features\Procurement\Events\PurchaseOrderSent($order));

            return $order->fresh(['items', 'supplier', 'supplierParty', 'purchaseRequest', 'contract']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function confirm(PurchaseOrder $order, array $proposalData): PurchaseOrder
    {
        if (! $order->canBeConfirmed()) {
            throw new \DomainException(trans_message('procurement.purchase_orders.invalid_status_for_confirm'));
        }

        DB::beginTransaction();

        try {
            $order->update([
                'status' => PurchaseOrderStatusEnum::CONFIRMED,
                'confirmed_at' => now(),
                'total_amount' => $proposalData['total_amount'] ?? $order->total_amount,
            ]);

            $this->syncDeliveryFromOrder($order, $order->purchaseRequest);

            if (isset($proposalData['items'])) {
                app(SupplierProposalService::class)->createFromOrder($order, $proposalData);
            }

            DB::commit();

            $this->invalidateCache($order->organization_id);

            return $order->fresh(['supplier', 'supplierParty', 'proposals', 'items', 'purchaseRequest']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function receiveMaterials(
        PurchaseOrder $order,
        int $warehouseId,
        array $items,
        int $userId,
        array $receiptData = []
    ): PurchaseOrder {
        $this->lifecycleService->assertCanReceiveMaterials($order, $items);
        $this->paymentGateService->assertCanReceive($order, $items);

        $warehouse = $this->resolveReceiptWarehouse($order, $warehouseId);
        $orderItems = $this->resolveOrderItems($order, $items);

        DB::beginTransaction();

        try {
            $receiptNumber = $this->generateReceiptNumber();
            $receiptDate = $receiptData['receipt_date'] ?? now()->toDateString();
            $receiptMetadata = is_array($receiptData['metadata'] ?? null) ? $receiptData['metadata'] : [];
            $receiptMetadata['receipt_document'] = $this->buildReceiptDocument(
                $order,
                $warehouse,
                $orderItems,
                $items,
                $receiptNumber,
                $receiptDate
            );

            $receipt = $order->receipts()->create([
                'organization_id' => $order->organization_id,
                'warehouse_id' => $warehouse->id,
                'received_by_user_id' => $userId,
                'receipt_number' => $receiptNumber,
                'receipt_date' => $receiptDate,
                'notes' => $receiptData['notes'] ?? null,
                'metadata' => $receiptMetadata,
            ]);

            foreach ($items as $item) {
                $quantity = (float) $item['quantity_received'];
                $price = (float) $item['price'];

                $receipt->lines()->create([
                    'purchase_order_item_id' => (int) $item['item_id'],
                    'quantity_received' => $quantity,
                    'price' => $price,
                    'total_amount' => round($quantity * $price, 2),
                    'metadata' => $item['metadata'] ?? null,
                ]);
            }

            $order->update([
                'status' => $this->lifecycleService->resolveOrderReceiptStatus($order),
            ]);

            event(new \App\BusinessModules\Features\Procurement\Events\MaterialReceivedFromSupplier(
                $order,
                $warehouse->id,
                $items,
                $userId
            ));

            $receipt->loadMissing('lines');
            $order->loadMissing('items');

            $this->auditService->record(
                ProcurementAuditEventTypeEnum::MATERIALS_RECEIVED->value,
                $order,
                (int) $order->organization_id,
                $userId,
                $order->supplier_party_id,
                [
                    'order_number' => $order->order_number,
                    'status' => $order->status->value,
                    'receipt_number' => $receipt->receipt_number,
                    'receipt_date' => $receipt->receipt_date?->format('Y-m-d'),
                    'warehouse_id' => $warehouse->id,
                    'warehouse_name' => $warehouse->name,
                    'supplier_name' => $this->supplierName(is_array($order->supplier_snapshot) ? $order->supplier_snapshot : []),
                    'items_count' => count($items),
                    'total_received_amount' => $receipt->lines->sum('total_amount'),
                    'items' => $this->receivedItemsPayload($order, $items),
                    'notes' => $receipt->notes,
                ]
            );

            DB::commit();

            $this->invalidateCache($order->organization_id);

            Log::info('procurement.materials_received', [
                'purchase_order_id' => $order->id,
                'warehouse_id' => $warehouse->id,
                'items_count' => $orderItems->count(),
                'user_id' => $userId,
            ]);

            return $order->fresh([
                'items.receiptLines',
                'supplier',
                'externalSupplierContact',
                'supplierParty',
                'purchaseRequest',
                'receipts.warehouse',
                'receipts.receivedByUser',
                'receipts.lines',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('procurement.materials_receive_failed', [
                'purchase_order_id' => $order->id,
                'warehouse_id' => $warehouse->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function markInDelivery(PurchaseOrder $order): PurchaseOrder
    {
        if ($order->status !== PurchaseOrderStatusEnum::CONFIRMED) {
            throw new \DomainException(trans_message('procurement.purchase_orders.invalid_status_for_delivery'));
        }

        DB::beginTransaction();

        try {
            $order->update([
                'status' => PurchaseOrderStatusEnum::IN_DELIVERY,
            ]);

            $this->markDeliveryInTransit($order);

            DB::commit();

            $this->invalidateCache($order->organization_id);

            Log::info('procurement.purchase_order.in_delivery', [
                'purchase_order_id' => $order->id,
            ]);

            return $order->fresh(['items', 'supplier', 'supplierParty', 'purchaseRequest']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function createContractFromOrder(PurchaseOrder $order): Contract
    {
        if ($order->hasContract()) {
            throw new \DomainException(trans_message('procurement.purchase_orders.contract_already_exists'));
        }

        return app(PurchaseContractService::class)->createFromOrder($order);
    }

    public function buildReceiptDocumentPreview(
        PurchaseOrder $order,
        int $warehouseId,
        array $items,
        ?string $receiptDate = null
    ): array {
        $this->lifecycleService->assertCanReceiveMaterials($order, $items);
        $this->paymentGateService->assertCanReceive($order, $items);

        $warehouse = $this->resolveReceiptWarehouse($order, $warehouseId);
        $orderItems = $this->resolveOrderItems($order, $items);

        return $this->buildReceiptDocument(
            $order,
            $warehouse,
            $orderItems,
            $items,
            trans_message('procurement.receipt_document.pending_number'),
            $receiptDate ?: now()->toDateString()
        );
    }

    private function resolveReceiptWarehouse(PurchaseOrder $order, int $warehouseId): OrganizationWarehouse
    {
        $warehouse = OrganizationWarehouse::query()
            ->where('organization_id', $order->organization_id)
            ->where('id', $warehouseId)
            ->where('is_active', true)
            ->first();

        if (! $warehouse) {
            throw new \DomainException(trans_message('procurement.purchase_orders.warehouse_not_found'));
        }

        return $warehouse;
    }

    private function resolveOrderItems(PurchaseOrder $order, array $items): Collection
    {
        $orderItemIds = collect($items)
            ->pluck('item_id')
            ->map(static fn ($value) => (int) $value)
            ->unique()
            ->values();

        $orderItems = $order->items()
            ->with('material')
            ->whereIn('id', $orderItemIds)
            ->get()
            ->keyBy('id');

        if ($orderItems->count() !== $orderItemIds->count()) {
            throw new \DomainException(trans_message('procurement.purchase_orders.item_not_found'));
        }

        return $orderItems->values();
    }

    private function buildReceiptDocument(
        PurchaseOrder $order,
        OrganizationWarehouse $warehouse,
        Collection $orderItems,
        array $items,
        string $receiptNumber,
        string $receiptDate
    ): array {
        $order->loadMissing(['organization', 'supplier', 'externalSupplierContact', 'supplierParty']);
        $orderItemsById = $orderItems->keyBy('id');
        $rows = collect($items)
            ->values()
            ->map(function (array $item, int $index) use ($orderItemsById): array {
                $orderItem = $orderItemsById->get((int) $item['item_id']);
                $quantity = (float) $item['quantity_received'];
                $price = (float) $item['price'];
                $amount = round($quantity * $price, 2);
                $unit = trim((string) ($orderItem?->unit ?? ''));

                return [
                    'row_number' => $index + 1,
                    'name' => $orderItem?->material_name ?: $orderItem?->material?->name,
                    'code' => $orderItem?->material?->code,
                    'unit_name' => $unit,
                    'okei_code' => $this->okeiCode($unit),
                    'package_type' => null,
                    'quantity_in_package' => null,
                    'places_count' => null,
                    'gross_weight' => null,
                    'quantity' => $quantity,
                    'price' => $price,
                    'amount_without_vat' => $amount,
                    'vat_rate' => null,
                    'vat_amount' => 0.0,
                    'amount_with_vat' => $amount,
                ];
            })
            ->all();

        $amountWithVat = round((float) collect($rows)->sum('amount_with_vat'), 2);

        return [
            'form_code' => 'ТОРГ-12',
            'okud' => '0330212',
            'title' => trans_message('procurement.receipt_document.torg12_title'),
            'approved_by' => trans_message('procurement.receipt_document.torg12_approved_by'),
            'document_number' => $receiptNumber,
            'document_date' => $receiptDate,
            'operation_type' => trans_message('procurement.receipt_document.operation_type'),
            'basis' => [
                'document_type' => trans_message('procurement.receipt_document.basis_order'),
                'number' => $order->order_number,
                'date' => $order->order_date?->format('Y-m-d'),
            ],
            'shipper' => $this->supplierDocumentParty($order),
            'supplier' => $this->supplierDocumentParty($order),
            'consignee' => $this->organizationDocumentParty($order, $warehouse),
            'payer' => $this->organizationDocumentParty($order, $warehouse),
            'warehouse' => [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'address' => $warehouse->address,
            ],
            'rows' => $rows,
            'totals' => [
                'rows_count' => count($rows),
                'quantity' => round((float) collect($rows)->sum('quantity'), 3),
                'amount_without_vat' => $amountWithVat,
                'vat_amount' => 0.0,
                'amount_with_vat' => $amountWithVat,
            ],
            'signatures' => [
                'released_by' => trans_message('procurement.receipt_document.signatures.released_by'),
                'chief_accountant' => trans_message('procurement.receipt_document.signatures.chief_accountant'),
                'accepted_by' => trans_message('procurement.receipt_document.signatures.accepted_by'),
                'received_by' => trans_message('procurement.receipt_document.signatures.received_by'),
            ],
        ];
    }

    private function supplierDocumentParty(PurchaseOrder $order): array
    {
        $supplier = $order->supplier;
        $contact = $order->externalSupplierContact;
        $party = $order->supplierParty;
        $snapshot = is_array($order->supplier_snapshot) ? $order->supplier_snapshot : [];
        $partySnapshot = is_array($party?->snapshot) ? $party->snapshot : [];

        return [
            'name' => $supplier?->name
                ?? $contact?->name
                ?? $party?->display_name
                ?? $snapshot['display_name']
                ?? $snapshot['name']
                ?? null,
            'inn' => $supplier?->inn
                ?? $supplier?->tax_number
                ?? $contact?->tax_number
                ?? $party?->tax_id
                ?? $snapshot['tax_id']
                ?? null,
            'phone' => $supplier?->phone ?? $contact?->phone ?? $party?->phone ?? $snapshot['phone'] ?? null,
            'email' => $supplier?->email ?? $contact?->email ?? $party?->email ?? $snapshot['email'] ?? null,
            'address' => $supplier?->address ?? $contact?->address ?? $partySnapshot['address'] ?? $snapshot['address'] ?? null,
        ];
    }

    private function organizationDocumentParty(PurchaseOrder $order, OrganizationWarehouse $warehouse): array
    {
        $organization = $order->organization;

        return [
            'name' => $organization?->legal_name ?: $organization?->name,
            'inn' => $organization?->tax_number,
            'phone' => $organization?->phone,
            'email' => $organization?->email,
            'address' => $organization?->address,
            'warehouse_name' => $warehouse->name,
            'warehouse_address' => $warehouse->address,
        ];
    }

    private function okeiCode(?string $unit): ?string
    {
        $normalized = mb_strtolower(trim((string) $unit));

        return match ($normalized) {
            'шт', 'pcs', 'piece', 'pieces' => '796',
            'кг', 'kg' => '166',
            'т', 'tn', 'ton', 'tons' => '168',
            'м', 'm' => '006',
            'м2', 'м²', 'm2' => '055',
            'м3', 'м³', 'm3' => '113',
            'л', 'l', 'liter', 'litre' => '112',
            default => null,
        };
    }

    private function generateReceiptNumber(): string
    {
        $year = date('Y');
        $month = date('m');
        $prefix = sprintf('PR-%s%s', $year, $month);

        $lastReceiptNumber = PurchaseReceipt::withTrashed()
            ->where('receipt_number', 'like', $prefix.'-%')
            ->orderByDesc('receipt_number')
            ->pluck('receipt_number')
            ->first(static fn (string $receiptNumber): bool => preg_match('/^PR-\d{6}-(\d+)$/', $receiptNumber) === 1);

        $nextNumber = 1;
        if ($lastReceiptNumber && preg_match('/(\d+)$/', $lastReceiptNumber, $matches)) {
            $nextNumber = ((int) $matches[1]) + 1;
        }

        return sprintf('%s-%04d', $prefix, $nextNumber);
    }

    private function generateOrderNumber(): string
    {
        $year = date('Y');
        $month = date('m');
        $prefix = "ЗП-{$year}{$month}";

        $lastNumber = PurchaseOrder::query()
            ->where('order_number', 'like', $prefix.'-%')
            ->orderByDesc('order_number')
            ->value('order_number');

        $nextNumber = 1;
        if (is_string($lastNumber) && preg_match('/-(\d+)$/', $lastNumber, $matches) === 1) {
            $nextNumber = ((int) $matches[1]) + 1;
        }

        do {
            $orderNumber = sprintf('%s-%04d', $prefix, $nextNumber);
            $nextNumber++;
        } while (PurchaseOrder::query()->where('order_number', $orderNumber)->exists());

        return $orderNumber;
    }

    private function invalidateCache(int $organizationId): void
    {
        Cache::forget("procurement_purchase_orders_{$organizationId}");
    }

    private function supplierName(array $supplierSnapshot): ?string
    {
        $name = $supplierSnapshot['display_name'] ?? null;

        return $name === null ? null : (string) $name;
    }

    private function receivedItemsPayload(PurchaseOrder $order, array $items): array
    {
        $itemsById = $order->items->keyBy('id');

        return collect($items)
            ->map(static function (array $item) use ($itemsById): array {
                $orderItem = $itemsById->get((int) $item['item_id']);

                return [
                    'item_id' => (int) $item['item_id'],
                    'material_name' => $orderItem?->material_name,
                    'quantity_received' => (float) $item['quantity_received'],
                    'unit' => $orderItem?->unit,
                    'price' => (float) $item['price'],
                    'total_amount' => round((float) $item['quantity_received'] * (float) $item['price'], 2),
                ];
            })
            ->values()
            ->all();
    }

    private function syncDeliveryFromOrder(PurchaseOrder $order, PurchaseRequest $request, ?int $actorId = null): void
    {
        if (! $request->site_request_id) {
            return;
        }

        $delivery = ProjectMaterialDelivery::query()
            ->where('organization_id', $order->organization_id)
            ->where(function ($query) use ($request): void {
                $query->where('purchase_request_id', $request->id)
                    ->orWhere('site_request_id', $request->site_request_id);
            })
            ->first();

        $actor = $this->resolveDeliveryActor($request, $actorId);

        if (! $delivery || ! $actor) {
            return;
        }

        $this->deliveryService->linkPurchaseOrder($delivery, $order, $actor);
    }

    private function markDeliveryInTransit(PurchaseOrder $order): void
    {
        $request = $order->purchaseRequest;

        if (! $request || ! $request->site_request_id) {
            return;
        }

        $delivery = ProjectMaterialDelivery::query()
            ->where('organization_id', $order->organization_id)
            ->where('purchase_order_id', $order->id)
            ->first();

        $actor = $this->resolveDeliveryActor($request);

        if (! $delivery || ! $actor || $delivery->status?->canBeReceived()) {
            return;
        }

        $this->deliveryService->ship($delivery, $actor, [
            'quantity' => max((float) $delivery->requested_quantity, (float) $delivery->reserved_quantity),
            'notes' => trans_message('basic_warehouse.project_material_deliveries.in_transit_from_purchase_order'),
        ]);
    }

    private function resolveDeliveryActor(PurchaseRequest $request, ?int $actorId = null): ?User
    {
        $userId = $actorId ?: auth()->id() ?: $request->assigned_to ?: $request->siteRequest?->user_id;

        return $userId ? User::query()->find($userId) : null;
    }
}
