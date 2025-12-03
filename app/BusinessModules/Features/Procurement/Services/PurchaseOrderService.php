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

            DB::commit();

            $this->invalidateCache($request->organization_id);

            event(new \App\BusinessModules\Features\Procurement\Events\PurchaseOrderCreated($order));

            return $order->fresh(['supplier', 'purchaseRequest']);
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

        DB::beginTransaction();
        try {
            $order->update([
                'status' => PurchaseOrderStatusEnum::SENT,
                'sent_at' => now(),
            ]);

            DB::commit();

            $this->invalidateCache($order->organization_id);

            event(new \App\BusinessModules\Features\Procurement\Events\PurchaseOrderSent($order));

            return $order->fresh();
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

            return $order->fresh(['supplier', 'proposals']);
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

