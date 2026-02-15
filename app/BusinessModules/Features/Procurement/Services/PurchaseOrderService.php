<?php

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\Models\Supplier;
use App\Models\Contract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * Сервис для работы с заказами поставщикам
 */
class PurchaseOrderService
{
    public function __construct(
        private readonly PurchaseOrderPdfService $pdfService
    ) {}

    /**
     * Создать заказ из заявки на закупку
     */
    public function create(PurchaseRequest $request, int $supplierId, array $data): PurchaseOrder
    {
        $supplier = Supplier::findOrFail($supplierId);

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

            // Копируем позиции из заявки с объекта
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

    /**
     * Отправить заказ поставщику
     */
    public function sendToSupplier(PurchaseOrder $order): PurchaseOrder
    {
        if (!$order->canBeSent()) {
            throw new \DomainException('Заказ не может быть отправлен в текущем статусе');
        }

        if (!$order->supplier || !$order->supplier->email) {
            throw new \DomainException('У поставщика не указан контактный email');
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
                    'sent_by_user_id' => auth()->id()
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

    /**
     * Подтвердить заказ с КП
     */
    public function confirm(PurchaseOrder $order, array $proposalData): PurchaseOrder
    {
        if (!$order->canBeConfirmed()) {
            throw new \DomainException('Заказ не может быть подтвержден в текущем статусе');
        }

        DB::beginTransaction();
        try {
            $order->update([
                'status' => PurchaseOrderStatusEnum::CONFIRMED,
                'confirmed_at' => now(),
                'total_amount' => $proposalData['total_amount'] ?? $order->total_amount,
            ]);

            // Создаем КП, если передано
            if (isset($proposalData['items'])) {
                $proposalService = app(SupplierProposalService::class);
                $proposalService->createFromOrder($order, $proposalData);
            }

            DB::commit();

            $this->invalidateCache($order->organization_id);

            return $order->fresh(['supplier', 'proposals', 'items', 'purchaseRequest']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Получить материалы от поставщика
     * 
     * @param PurchaseOrder $order
     * @param int $warehouseId ID склада для приема материалов
     * @param array $items Массив позиций с данными о полученных материалах
     *        [['item_id' => 1, 'quantity_received' => 10, 'price' => 100.50], ...]
     * @param int $userId ID пользователя, принимающего материалы
     * @return PurchaseOrder
     */
    public function receiveMaterials(
        PurchaseOrder $order,
        int $warehouseId,
        array $items,
        int $userId
    ): PurchaseOrder {
        // Проверяем что заказ может быть доставлен
        if (!in_array($order->status, [
            PurchaseOrderStatusEnum::CONFIRMED,
            PurchaseOrderStatusEnum::IN_DELIVERY
        ])) {
            throw new \DomainException('Материалы можно принять только для подтвержденных заказов или заказов в доставке');
        }

        DB::beginTransaction();
        try {
            // Обновляем статус заказа на "Доставлен"
            $order->update([
                'status' => PurchaseOrderStatusEnum::DELIVERED,
            ]);

            // Отправляем событие для обновления склада
            event(new \App\BusinessModules\Features\Procurement\Events\MaterialReceivedFromSupplier(
                $order,
                $warehouseId,
                $items,
                $userId
            ));

            DB::commit();

            \Log::info('procurement.materials_received', [
                'purchase_order_id' => $order->id,
                'warehouse_id' => $warehouseId,
                'items_count' => count($items),
                'user_id' => $userId,
            ]);

            return $order->fresh(['items', 'supplier', 'purchaseRequest']);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('procurement.materials_receive_failed', [
                'purchase_order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Перевести заказ в статус "В доставке"
     */
    public function markInDelivery(PurchaseOrder $order): PurchaseOrder
    {
        if ($order->status !== PurchaseOrderStatusEnum::CONFIRMED) {
            throw new \DomainException('Только подтвержденные заказы могут быть переведены в доставку');
        }

        DB::beginTransaction();
        try {
            $order->update([
                'status' => PurchaseOrderStatusEnum::IN_DELIVERY,
            ]);

            DB::commit();

            $this->invalidateCache($order->organization_id);

            \Log::info('procurement.purchase_order.in_delivery', [
                'purchase_order_id' => $order->id,
            ]);

            return $order->fresh(['items', 'supplier', 'purchaseRequest']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Создать договор поставки из заказа
     */
    public function createContractFromOrder(PurchaseOrder $order): Contract
    {
        if ($order->hasContract()) {
            throw new \DomainException('Договор уже создан для этого заказа');
        }

        $contractService = app(PurchaseContractService::class);
        return $contractService->createFromOrder($order);
    }

    /**
     * Генерировать номер заказа
     */
    private function generateOrderNumber(int $organizationId): string
    {
        $year = date('Y');
        $month = date('m');

        $lastOrder = PurchaseOrder::where('organization_id', $organizationId)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderBy('id', 'desc')
            ->first();

        $nextNumber = 1;
        if ($lastOrder && preg_match('/(\d+)$/', $lastOrder->order_number, $matches)) {
            $nextNumber = intval($matches[1]) + 1;
        }

        return sprintf('ЗП-%s%s-%04d', $year, $month, $nextNumber);
    }

    /**
     * Инвалидация кеша
     */
    private function invalidateCache(int $organizationId): void
    {
        Cache::forget("procurement_purchase_orders_{$organizationId}");
    }
}

