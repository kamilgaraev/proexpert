<?php

namespace App\BusinessModules\Features\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\Procurement\Services\SupplierProposalService;
use App\BusinessModules\Features\Procurement\Http\Requests\StoreSupplierProposalRequest;
use App\BusinessModules\Features\Procurement\Http\Resources\SupplierProposalResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Контроллер для коммерческих предложений
 */
class SupplierProposalController extends Controller
{
    public function __construct(
        private readonly SupplierProposalService $service
    ) {}

    /**
     * Список КП
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $perPage = min($request->input('per_page', 15), 100);

            $query = \App\BusinessModules\Features\Procurement\Models\SupplierProposal::forOrganization($organizationId)
                ->with(['supplier', 'purchaseOrder']);

            if ($request->has('purchase_order_id')) {
                $query->forOrder($request->input('purchase_order_id'));
            }

            if ($request->has('status')) {
                $query->withStatus($request->input('status'));
            }

            $proposals = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => SupplierProposalResource::collection($proposals->items()),
                'meta' => [
                    'current_page' => $proposals->currentPage(),
                    'per_page' => $proposals->perPage(),
                    'total' => $proposals->total(),
                    'last_page' => $proposals->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('procurement.supplier_proposals.index.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить коммерческие предложения',
            ], 500);
        }
    }

    /**
     * Показать КП
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $proposal = \App\BusinessModules\Features\Procurement\Models\SupplierProposal::forOrganization($organizationId)
                ->with(['supplier', 'purchaseOrder'])
                ->find($id);

            if (!$proposal) {
                return response()->json([
                    'success' => false,
                    'error' => 'Коммерческое предложение не найдено',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => new SupplierProposalResource($proposal),
            ]);
        } catch (\Exception $e) {
            \Log::error('procurement.supplier_proposals.show.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить коммерческое предложение',
            ], 500);
        }
    }

    /**
     * Создать КП
     */
    public function store(StoreSupplierProposalRequest $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $order = \App\BusinessModules\Features\Procurement\Models\PurchaseOrder::forOrganization($organizationId)
                ->findOrFail($request->input('purchase_order_id'));

            $proposal = $this->service->createFromOrder($order, $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Коммерческое предложение успешно создано',
                'data' => new SupplierProposalResource($proposal),
            ], 201);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('procurement.supplier_proposals.store.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось создать коммерческое предложение',
            ], 500);
        }
    }

    /**
     * Принять КП
     */
    public function accept(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $proposal = \App\BusinessModules\Features\Procurement\Models\SupplierProposal::forOrganization($organizationId)
                ->findOrFail($id);

            $accepted = $this->service->accept($proposal);

            return response()->json([
                'success' => true,
                'message' => 'Коммерческое предложение принято',
                'data' => new SupplierProposalResource($accepted),
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('procurement.supplier_proposals.accept.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось принять коммерческое предложение',
            ], 500);
        }
    }

    /**
     * Отклонить КП
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $proposal = \App\BusinessModules\Features\Procurement\Models\SupplierProposal::forOrganization($organizationId)
                ->findOrFail($id);

            $request->validate([
                'reason' => 'required|string|max:1000',
            ]);

            $rejected = $this->service->reject($proposal, $request->input('reason'));

            return response()->json([
                'success' => true,
                'message' => 'Коммерческое предложение отклонено',
                'data' => new SupplierProposalResource($rejected),
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('procurement.supplier_proposals.reject.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось отклонить коммерческое предложение',
            ], 500);
        }
    }
}

