<?php

namespace App\BusinessModules\Features\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\Procurement\Services\PurchaseRequestService;
use App\BusinessModules\Features\Procurement\Http\Requests\StorePurchaseRequestRequest;
use App\BusinessModules\Features\Procurement\Http\Resources\PurchaseRequestResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Контроллер для заявок на закупку
 */
class PurchaseRequestController extends Controller
{
    public function __construct(
        private readonly PurchaseRequestService $service
    ) {}

    /**
     * Список заявок на закупку
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $perPage = min($request->input('per_page', 15), 100);

            $filters = $request->only([
                'status',
                'site_request_id',
                'assigned_to',
                'sort_by',
                'sort_dir',
            ]);

            $requests = $this->service->paginate($organizationId, $perPage, $filters);

            return response()->json([
                'success' => true,
                'data' => PurchaseRequestResource::collection($requests->items()),
                'meta' => [
                    'current_page' => $requests->currentPage(),
                    'per_page' => $requests->perPage(),
                    'total' => $requests->total(),
                    'last_page' => $requests->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('procurement.purchase_requests.index.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить заявки на закупку',
            ], 500);
        }
    }

    /**
     * Показать заявку на закупку
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $purchaseRequest = $this->service->find($id, $organizationId);

            if (!$purchaseRequest) {
                return response()->json([
                    'success' => false,
                    'error' => 'Заявка на закупку не найдена',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => new PurchaseRequestResource($purchaseRequest),
            ]);
        } catch (\Exception $e) {
            \Log::error('procurement.purchase_requests.show.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить заявку на закупку',
            ], 500);
        }
    }

    /**
     * Создать заявку на закупку
     */
    public function store(StorePurchaseRequestRequest $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $purchaseRequest = $this->service->create($organizationId, $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Заявка на закупку успешно создана',
                'data' => new PurchaseRequestResource($purchaseRequest),
            ], 201);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('procurement.purchase_requests.store.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось создать заявку на закупку',
            ], 500);
        }
    }

    /**
     * Одобрить заявку
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $userId = auth()->id();

            $purchaseRequest = $this->service->find($id, $organizationId);

            if (!$purchaseRequest) {
                return response()->json([
                    'success' => false,
                    'error' => 'Заявка на закупку не найдена',
                ], 404);
            }

            $approved = $this->service->approve($purchaseRequest, $userId);

            return response()->json([
                'success' => true,
                'message' => 'Заявка на закупку одобрена',
                'data' => new PurchaseRequestResource($approved),
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('procurement.purchase_requests.approve.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось одобрить заявку на закупку',
            ], 500);
        }
    }

    /**
     * Отклонить заявку
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $userId = auth()->id();

            $purchaseRequest = $this->service->find($id, $organizationId);

            if (!$purchaseRequest) {
                return response()->json([
                    'success' => false,
                    'error' => 'Заявка на закупку не найдена',
                ], 404);
            }

            $request->validate([
                'reason' => 'required|string|max:1000',
            ]);

            $rejected = $this->service->reject($purchaseRequest, $userId, $request->input('reason'));

            return response()->json([
                'success' => true,
                'message' => 'Заявка на закупку отклонена',
                'data' => new PurchaseRequestResource($rejected),
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('procurement.purchase_requests.reject.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось отклонить заявку на закупку',
            ], 500);
        }
    }

    /**
     * Создать заказ поставщику из заявки
     */
    public function createOrder(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $purchaseRequest = $this->service->find($id, $organizationId);

            if (!$purchaseRequest) {
                return response()->json([
                    'success' => false,
                    'error' => 'Заявка на закупку не найдена',
                ], 404);
            }

            $request->validate([
                'supplier_id' => 'required|exists:suppliers,id',
            ]);

            $order = $this->service->assignToSupplier($purchaseRequest, $request->input('supplier_id'));

            return response()->json([
                'success' => true,
                'message' => 'Заказ поставщику успешно создан',
                'data' => [
                    'purchase_request' => new PurchaseRequestResource($purchaseRequest->fresh()),
                    'purchase_order' => new \App\BusinessModules\Features\Procurement\Http\Resources\PurchaseOrderResource($order),
                ],
            ], 201);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('procurement.purchase_requests.create_order.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось создать заказ поставщику',
            ], 500);
        }
    }
}

