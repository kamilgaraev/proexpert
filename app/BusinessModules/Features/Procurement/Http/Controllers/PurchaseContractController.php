<?php

namespace App\BusinessModules\Features\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\Procurement\Services\PurchaseContractService;
use App\BusinessModules\Features\Procurement\Http\Requests\CreatePurchaseContractRequest;
use App\BusinessModules\Features\Procurement\Http\Resources\PurchaseContractResource;
use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Контроллер для договоров поставки
 */
class PurchaseContractController extends Controller
{
    public function __construct(
        private readonly PurchaseContractService $contractService
    ) {}

    /**
     * Список договоров поставки
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $perPage = min($request->input('per_page', 15), 100);

            $query = Contract::forOrganization($organizationId)
                ->procurementContracts()
                ->with(['supplier', 'project', 'organization']);

            if ($request->has('supplier_id')) {
                $supplierId = $request->input('supplier_id');
                if ($supplierId !== null && $supplierId !== '') {
                    $query->where('supplier_id', (int)$supplierId);
                }
            }

            if ($request->has('project_id')) {
                $projectId = $request->input('project_id');
                if ($projectId !== null && $projectId !== '') {
                    $query->where('project_id', (int)$projectId);
                }
            }

            if ($request->has('status')) {
                $status = $request->input('status');
                if ($status !== null && $status !== '') {
                    $query->where('status', $status);
                }
            }

            $sortBy = $request->input('sort_by', 'created_at');
            $sortDir = $request->input('sort_dir', 'desc');

            $allowedSortFields = ['created_at', 'updated_at', 'date', 'number', 'total_amount', 'status'];
            if (in_array($sortBy, $allowedSortFields)) {
                $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
            } else {
                $query->orderBy('created_at', 'desc');
            }

            $contracts = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => PurchaseContractResource::collection($contracts->items()),
                'meta' => [
                    'current_page' => $contracts->currentPage(),
                    'per_page' => $contracts->perPage(),
                    'total' => $contracts->total(),
                    'last_page' => $contracts->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('procurement.contracts.index.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить договоры поставки',
            ], 500);
        }
    }

    /**
     * Показать договор поставки
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $contract = Contract::forOrganization($organizationId)
                ->procurementContracts()
                ->with(['supplier', 'project', 'organization'])
                ->find($id);

            if (!$contract) {
                return response()->json([
                    'success' => false,
                    'error' => 'Договор поставки не найден',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => new PurchaseContractResource($contract),
            ]);
        } catch (\Exception $e) {
            \Log::error('procurement.contracts.show.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить договор поставки',
            ], 500);
        }
    }

    /**
     * Создать договор поставки
     */
    public function store(CreatePurchaseContractRequest $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            // Валидация через сервис
            $this->contractService->validateProcurementContractCreation($request->validated(), $organizationId);

            // Используем существующий ContractService для создания договора
            $contractService = app(\App\Services\Contract\ContractService::class);
            $contractDTO = \App\DTOs\Contract\ContractDTO::fromRequest($request);
            $contract = $contractService->createContract($contractDTO, $organizationId);

            return response()->json([
                'success' => true,
                'message' => 'Договор поставки успешно создан',
                'data' => new PurchaseContractResource($contract),
            ], 201);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('procurement.contracts.store.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось создать договор поставки',
            ], 500);
        }
    }
}

