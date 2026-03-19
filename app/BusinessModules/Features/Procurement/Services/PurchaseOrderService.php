<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\Models\Contract;
use App\Models\Supplier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use function trans_message;

class PurchaseOrderService
{
    public function __construct(
        private readonly PurchaseOrderPdfService $pdfService
    ) {
    }

    public function create(PurchaseRequest $request, int $supplierId, array $data): PurchaseOrder
    {
        if ($request->purchaseOrders()->exists()) {
            throw new \DomainException(trans_message('procurement.purchase_orders.already_exists_for_request'));
        }

        $supplier = Supplier::query()
            ->where('organization_id', $request->organization_id)
            ->where('id', $supplierId)
            ->first();

        if (!$supplier) {
            throw new \DomainException(trans_message('procurement.purchase_orders.supplier_not_found'));
        }

        DB::beginTransaction();

        try {
            $orderNumber = $this->generateOrderNumber($request->organization_id);

            $order = PurchaseOrder::create([
                'organization_id' => $request->organization_id,
                'purchase_request_id' => $request->id,
                'supplier_id' => $supplierId,
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

            DB::commit();

            $this->invalidateCache($request->organization_id);

            event(new \App\BusinessModules\Features\Procurement\Events\PurchaseOrderCreated($order));

            return $order->fresh(['supplier', 'purchaseRequest', 'items']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function sendToSupplier(PurchaseOrder $order): PurchaseOrder
    {
        if (!$order->canBeSent()) {
            throw new \DomainException(trans_message('procurement.purchase_orders.invalid_status_for_send'));
        }

        if (!$order->supplier || !$order->supplier->email) {
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

            return $order->fresh(['items', 'supplier', 'purchaseRequest', 'contract']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function confirm(PurchaseOrder $order, array $proposalData): PurchaseOrder
    {
        if (!$order->canBeConfirmed()) {
            throw new \DomainException(trans_message('procurement.purchase_orders.invalid_status_for_confirm'));
        }

        DB::beginTransaction();

        try {
            $order->update([
                'status' => PurchaseOrderStatusEnum::CONFIRMED,
                'confirmed_at' => now(),
                'total_amount' => $proposalData['total_amount'] ?? $order->total_amount,
            ]);

            if (isset($proposalData['items'])) {
                app(SupplierProposalService::class)->createFromOrder($order, $proposalData);
            }

            DB::commit();

            $this->invalidateCache($order->organization_id);

            return $order->fresh(['supplier', 'proposals', 'items', 'purchaseRequest']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function receiveMaterials(
        PurchaseOrder $order,
        int $warehouseId,
        array $items,
        int $userId
    ): PurchaseOrder {
        if (!in_array($order->status, [
            PurchaseOrderStatusEnum::CONFIRMED,
            PurchaseOrderStatusEnum::IN_DELIVERY,
        ], true)) {
            throw new \DomainException(trans_message('procurement.purchase_orders.invalid_status_for_receive'));
        }

        $warehouse = OrganizationWarehouse::query()
            ->where('organization_id', $order->organization_id)
            ->where('id', $warehouseId)
            ->where('is_active', true)
            ->first();

        if (!$warehouse) {
            throw new \DomainException(trans_message('procurement.purchase_orders.warehouse_not_found'));
        }

        $orderItems = $this->resolveOrderItems($order, $items);

        DB::beginTransaction();

        try {
            event(new \App\BusinessModules\Features\Procurement\Events\MaterialReceivedFromSupplier(
                $order,
                $warehouse->id,
                $items,
                $userId
            ));

            $order->update([
                'status' => PurchaseOrderStatusEnum::DELIVERED,
            ]);

            DB::commit();

            $this->invalidateCache($order->organization_id);

            Log::info('procurement.materials_received', [
                'purchase_order_id' => $order->id,
                'warehouse_id' => $warehouse->id,
                'items_count' => $orderItems->count(),
                'user_id' => $userId,
            ]);

            return $order->fresh(['items', 'supplier', 'purchaseRequest']);
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

            DB::commit();

            $this->invalidateCache($order->organization_id);

            Log::info('procurement.purchase_order.in_delivery', [
                'purchase_order_id' => $order->id,
            ]);

            return $order->fresh(['items', 'supplier', 'purchaseRequest']);
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

    private function resolveOrderItems(PurchaseOrder $order, array $items): Collection
    {
        $orderItemIds = collect($items)
            ->pluck('item_id')
            ->map(static fn ($value) => (int) $value)
            ->unique()
            ->values();

        $orderItems = $order->items()
            ->whereIn('id', $orderItemIds)
            ->get()
            ->keyBy('id');

        if ($orderItems->count() !== $orderItemIds->count()) {
            throw new \DomainException(trans_message('procurement.purchase_orders.item_not_found'));
        }

        return $orderItems->values();
    }

    private function generateOrderNumber(int $organizationId): string
    {
        $year = date('Y');
        $month = date('m');

        $lastOrder = PurchaseOrder::where('organization_id', $organizationId)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderByDesc('id')
            ->first();

        $nextNumber = 1;
        if ($lastOrder && preg_match('/(\d+)$/', $lastOrder->order_number, $matches)) {
            $nextNumber = ((int) $matches[1]) + 1;
        }

        return sprintf('ЗП-%s%s-%04d', $year, $month, $nextNumber);
    }

    private function invalidateCache(int $organizationId): void
    {
        Cache::forget("procurement_purchase_orders_{$organizationId}");
    }
}
