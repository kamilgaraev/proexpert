<?php

namespace App\BusinessModules\Features\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\Procurement\Services\PurchaseOrderService;
use App\BusinessModules\Features\Procurement\Http\Requests\StorePurchaseOrderRequest;
use App\BusinessModules\Features\Procurement\Http\Resources\PurchaseOrderResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Контроллер для заказов поставщикам
 */
class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $service
    ) {}

    /**
     * Список заказов
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $perPage = min($request->input('per_page', 15), 100);

            // TODO: Добавить сервис для пагинации заказов
            $orders = \App\BusinessModules\Features\Procurement\Models\PurchaseOrder::forOrganization($organizationId)
                ->with(['supplier', 'purchaseRequest', 'contract'])
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => PurchaseOrderResource::collection($orders->items()),
                'meta' => [
                    'current_page' => $orders->currentPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                    'last_page' => $orders->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('procurement.purchase_orders.index.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить заказы',
            ], 500);
        }
    }

    /**
     * Показать заказ
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $order = \App\BusinessModules\Features\Procurement\Models\PurchaseOrder::forOrganization($organizationId)
                ->with(['supplier', 'purchaseRequest', 'contract', 'proposals'])
                ->find($id);

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'error' => 'Заказ не найден',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => new PurchaseOrderResource($order),
            ]);
        } catch (\Exception $e) {
            \Log::error('procurement.purchase_orders.show.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить заказ',
            ], 500);
        }
    }

    /**
     * Создать заказ
     */
    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $purchaseRequest = \App\BusinessModules\Features\Procurement\Models\PurchaseRequest::forOrganization($organizationId)
                ->findOrFail($request->input('purchase_request_id'));

            $order = $this->service->create($purchaseRequest, $request->input('supplier_id'), $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Заказ поставщику успешно создан',
                'data' => new PurchaseOrderResource($order),
            ], 201);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('procurement.purchase_orders.store.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось создать заказ',
            ], 500);
        }
    }

    /**
     * Отправить заказ поставщику
     */
    public function send(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $order = \App\BusinessModules\Features\Procurement\Models\PurchaseOrder::forOrganization($organizationId)
                ->findOrFail($id);

            $sent = $this->service->sendToSupplier($order);

            return response()->json([
                'success' => true,
                'message' => 'Заказ отправлен поставщику',
                'data' => new PurchaseOrderResource($sent),
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('procurement.purchase_orders.send.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось отправить заказ',
            ], 500);
        }
    }

    /**
     * Подтвердить заказ
     */
    public function confirm(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $order = \App\BusinessModules\Features\Procurement\Models\PurchaseOrder::forOrganization($organizationId)
                ->findOrFail($id);

            $request->validate([
                'total_amount' => 'sometimes|numeric|min:0',
                'items' => 'sometimes|array',
            ]);

            $confirmed = $this->service->confirm($order, $request->only(['total_amount', 'items']));

            return response()->json([
                'success' => true,
                'message' => 'Заказ подтвержден',
                'data' => new PurchaseOrderResource($confirmed),
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('procurement.purchase_orders.confirm.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось подтвердить заказ',
            ], 500);
        }
    }

    /**
     * Создать договор поставки из заказа
     */
    public function createContract(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $order = \App\BusinessModules\Features\Procurement\Models\PurchaseOrder::forOrganization($organizationId)
                ->findOrFail($id);

            $contract = $this->service->createContractFromOrder($order);

            return response()->json([
                'success' => true,
                'message' => 'Договор поставки успешно создан',
                'data' => [
                    'purchase_order' => new PurchaseOrderResource($order->fresh()),
                    'contract' => new \App\BusinessModules\Features\Procurement\Http\Resources\PurchaseContractResource($contract),
                ],
            ], 201);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('procurement.purchase_orders.create_contract.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось создать договор поставки',
            ], 500);
        }
    }
}

